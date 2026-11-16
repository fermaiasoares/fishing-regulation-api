<?php

namespace App\Legal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Classifies a species name against the official lists that apply to an area.
 *
 * Lists are exemplificative ("tais como"), so a name that is not found is
 * "unknown", never presumed non-native. A name classified differently by two
 * applicable lists, or listed as both native and capturable, is "conflicting".
 */
final class SpeciesClassifier
{
    public const NATIVE = 'native';

    public const NON_NATIVE = 'non_native';

    public const EXCLUDED = 'excluded';

    public const CONFLICTING = 'conflicting';

    public const UNKNOWN = 'unknown';

    /** List keys and the classification each one means. */
    public const KEYS = [
        'native' => self::NATIVE,
        'non_native' => self::NON_NATIVE,
        'aloctone' => self::NON_NATIVE,
        'exotic' => self::NON_NATIVE,
        'hybrid' => self::NON_NATIVE,
        'excluded' => self::EXCLUDED,
        'native_listed_as_capturable' => self::CONFLICTING,
    ];

    /** @var array<string, list<array{code: string, act: string, jurisdiction: string, areas: list<string>, payload: array}>> */
    private array $lists = [];

    /**
     * @param  list<string>  $areaLineage  qualified area codes of the point ("mg:doce", "br")
     * @param  list<int>  $catalogVersionIds  published version of each applicable jurisdiction
     * @return array{classification: string, matches: list<array{list: string, act: string, entry: string, classification: string}>, threatened: list<array{list: string, act: string, name: string, category: string, valid_from: ?string}>}
     */
    public function classify(string $name, array $areaLineage, array $catalogVersionIds): array
    {
        $query = self::normalize($name);
        $matches = [];

        $threatenedLists = [];
        foreach ($this->lists($catalogVersionIds) as $list) {
            if (array_intersect($list['areas'], $areaLineage) === []) {
                continue;
            }
            $payload = $list['payload'];
            if (isset($payload['threatened'])) {
                $threatenedLists[] = $list;

                continue;
            }
            $spelling = self::spellingOf($payload);
            foreach (self::KEYS as $key => $classification) {
                foreach ($payload[$key] ?? [] as $entry) {
                    if (self::entryMatches($entry, $query, $spelling)) {
                        $matches[] = ['list' => $list['code'], 'act' => $list['act'], 'entry' => $entry, 'classification' => $classification, '_spelling' => $spelling];
                    }
                }
            }
        }

        $threatened = self::threatened($query, $matches, $threatenedLists);
        $matches = array_map(function (array $match) {
            unset($match['_spelling']);

            return $match;
        }, $matches);

        return [
            'classification' => self::classificationOf($matches),
            'matches' => $matches,
            'threatened' => $threatened,
        ];
    }

    /**
     * Species lists of the given catalog versions, read once per instance.
     *
     * @param  list<int>  $catalogVersionIds
     * @return list<array{code: string, act: string, jurisdiction: string, areas: list<string>, payload: array}>
     */
    public function lists(array $catalogVersionIds): array
    {
        sort($catalogVersionIds);
        $key = implode(',', $catalogVersionIds);

        return $this->lists[$key] ??= DB::table('legal_species_lists')
            ->join('legal_acts', 'legal_acts.id', '=', 'legal_species_lists.legal_act_id')
            ->join('legal_catalog_versions', 'legal_catalog_versions.id', '=', 'legal_species_lists.catalog_version_id')
            ->whereIn('legal_species_lists.catalog_version_id', $catalogVersionIds)
            ->orderBy('legal_species_lists.code')
            ->get(['legal_species_lists.code', 'legal_species_lists.payload', 'legal_acts.code as act_code', 'legal_catalog_versions.jurisdiction'])
            ->map(function ($list) {
                $payload = json_decode($list->payload, true);

                return [
                    'code' => $list->code,
                    'act' => $list->act_code,
                    'jurisdiction' => $list->jurisdiction,
                    'areas' => array_values(array_filter(array_map('trim', explode(',', (string) ($payload['basin'] ?? ''))))),
                    'payload' => $payload,
                ];
            })
            ->all();
    }

    /**
     * Classification implied by the list entries a species matched.
     *
     * @param  list<array{classification: string}>  $matches
     */
    public static function classificationOf(array $matches): string
    {
        $classes = array_values(array_unique(array_column($matches, 'classification')));

        return match (true) {
            $classes === [] => self::UNKNOWN,
            in_array(self::CONFLICTING, $classes, true) => self::CONFLICTING,
            in_array(self::EXCLUDED, $classes, true) => self::EXCLUDED,
            count($classes) > 1 => self::CONFLICTING,
            default => $classes[0],
        };
    }

    /**
     * Spelling corrections of a list ("Cicla spp." -> "Cichla spp."), normalized.
     *
     * @return array<string, string>
     */
    public static function spellingOf(array $payload): array
    {
        $spelling = [];
        foreach ($payload['spelling'] ?? [] as $written => $valid) {
            $spelling[self::normalize($written)] = self::normalize($valid);
        }

        return $spelling;
    }

    /**
     * Threatened-species lists only carry scientific names, so the query is
     * checked directly and through the scientific names of the entries it
     * matched in other lists (e.g. "tambaqui" -> Colossoma macropomum).
     */
    private static function threatened(string $query, array $matches, array $lists): array
    {
        $names = [$query => true];
        foreach ($matches as $match) {
            foreach (self::scientificNames($match['entry'], $match['_spelling']) as $name) {
                $names[$name] = true;
            }
        }
        $found = [];
        foreach ($lists as $list) {
            foreach ($list['payload']['threatened'] as $species) {
                if (isset($names[self::normalize($species['name'])])) {
                    $found[] = [
                        'list' => $list['code'],
                        'act' => $list['act'],
                        'name' => $species['name'],
                        'category' => $species['category'],
                        'valid_from' => $list['payload']['valid_from'] ?? null,
                    ];
                }
            }
        }

        return $found;
    }

    /**
     * Normalized binomial names of an entry, with spelling corrections,
     * excluding "spp."/"sp." and hybrid crosses.
     *
     * @param  array<string, string>  $spelling
     * @return list<string>
     */
    public static function scientificNames(string $entry, array $spelling = []): array
    {
        $parsed = self::parseEntry($entry);
        if ($parsed['hybrid']) {
            return [];
        }
        $names = [];
        foreach ($parsed['scientific'] as $name) {
            foreach (self::spellings(self::normalize($name), $spelling) as $variant) {
                if (preg_match('/^[a-z]+ [a-z]+$/', $variant) === 1 && ! preg_match('/ spp?$/', $variant)) {
                    $names[$variant] = true;
                }
            }
        }

        return array_keys($names);
    }

    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9. ]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * Splits an entry such as "Caranha Preta ou Pirapitinga ou Pacu (Piaractus brachypomus)"
     * into its common names and scientific names, as written. A cross such as
     * "(Colossoma macropomum X Piaractus mesopotamicus)" is kept whole.
     *
     * @return array{common: list<string>, scientific: list<string>, hybrid: bool, note: ?string}
     */
    public static function parseEntry(string $entry): array
    {
        $common = $entry;
        $scientific = [];
        $hybrid = false;
        $note = null;
        if (preg_match('/^(.*?)\((.*)\)\s*$/u', $entry, $parts)) {
            $common = $parts[1];
            $inner = trim($parts[2]);
            if (preg_match('/^[A-Z][a-z]+\s/u', $inner)) {
                $hybrid = preg_match('/\s[xX×]\s/u', $inner) === 1;
                $scientific = $hybrid ? [$inner] : preg_split('/\s+e\s+|\s*,\s*/u', $inner);
            } else {
                $note = $inner;
            }
        } elseif (preg_match('/^[A-Z][a-z]+ (?:aff\. )?[a-z]+\.?$/u', $entry)) {
            $scientific = [$entry];
            $common = '';
        }

        return [
            'common' => array_values(array_filter(array_map('trim', preg_split('/\s*\/\s*|\s+ou\s+/iu', $common)), fn ($alias) => $alias !== '')),
            'scientific' => array_values(array_map('trim', $scientific)),
            'hybrid' => $hybrid,
            'note' => $note,
        ];
    }

    /**
     * An entry matches its common names and its scientific names, as written or
     * as corrected by the list's spelling table. "Cichla spp." matches any
     * species of the genus; "sp." (one unidentified species) only matches itself.
     * A cross names the hybrid's parents, so it only matches as a whole.
     *
     * @param  array<string, string>  $spelling  normalized written name => normalized valid name
     */
    public static function entryMatches(string $entry, string $query, array $spelling = []): bool
    {
        if ($query === '') {
            return false;
        }
        $parsed = self::parseEntry($entry);

        foreach ($parsed['common'] as $alias) {
            $alias = self::normalize($alias);
            if ($alias !== '' && ($alias === $query || rtrim($alias, 's') === rtrim($query, 's'))) {
                return true;
            }
        }

        foreach ($parsed['scientific'] as $written) {
            foreach (self::spellings(self::normalize($written), $spelling) as $name) {
                if ($name === $query) {
                    return true;
                }
                if (preg_match('/^([a-z]+) spp\.?$/', $name, $genus)
                    && preg_match('/^'.preg_quote($genus[1], '/').' [a-z]+/', $query)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A normalized name and, when the list corrects it, its valid spelling. */
    private static function spellings(string $name, array $spelling): array
    {
        return isset($spelling[$name]) ? [$name, $spelling[$name]] : [$name];
    }
}
