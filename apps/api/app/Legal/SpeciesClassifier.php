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
    private const KEYS = [
        'native' => self::NATIVE,
        'non_native' => self::NON_NATIVE,
        'aloctone' => self::NON_NATIVE,
        'exotic' => self::NON_NATIVE,
        'hybrid' => self::NON_NATIVE,
        'excluded' => self::EXCLUDED,
        'native_listed_as_capturable' => self::CONFLICTING,
    ];

    /**
     * @param  list<string>  $areaLineage
     * @return array{classification: string, matches: list<array{list: string, act: string, entry: string, classification: string}>}
     */
    public function classify(string $name, array $areaLineage, int $catalogVersionId): array
    {
        $query = self::normalize($name);
        $matches = [];

        $lists = DB::table('legal_species_lists')
            ->join('legal_acts', 'legal_acts.id', '=', 'legal_species_lists.legal_act_id')
            ->where('legal_species_lists.catalog_version_id', $catalogVersionId)
            ->orderBy('legal_species_lists.code')
            ->get(['legal_species_lists.code', 'legal_species_lists.payload', 'legal_acts.code as act_code']);

        foreach ($lists as $list) {
            $payload = json_decode($list->payload, true);
            $listAreas = array_map('trim', explode(',', (string) ($payload['basin'] ?? '')));
            if (array_intersect($listAreas, $areaLineage) === []) {
                continue;
            }
            foreach (self::KEYS as $key => $classification) {
                foreach ($payload[$key] ?? [] as $entry) {
                    if (self::entryMatches($entry, $query)) {
                        $matches[] = ['list' => $list->code, 'act' => $list->act_code, 'entry' => $entry, 'classification' => $classification];
                    }
                }
            }
        }

        $classes = array_values(array_unique(array_column($matches, 'classification')));
        $classification = match (true) {
            $classes === [] => self::UNKNOWN,
            in_array(self::CONFLICTING, $classes, true) => self::CONFLICTING,
            in_array(self::EXCLUDED, $classes, true) => self::EXCLUDED,
            count($classes) > 1 => self::CONFLICTING,
            default => $classes[0],
        };

        return ['classification' => $classification, 'matches' => $matches];
    }

    public static function normalize(string $value): string
    {
        $value = Str::lower(Str::ascii($value));
        $value = preg_replace('/[^a-z0-9. ]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }

    /**
     * An entry such as "Caranha Preta ou Pirapitinga ou Pacu (Piaractus brachypomus)"
     * matches its common names and its scientific names. "Cichla spp." matches any
     * species of the genus; "sp." (one unidentified species) only matches itself.
     * A cross such as "(Colossoma macropomum X Piaractus mesopotamicus)" names the
     * hybrid's parents, so it only matches as a whole.
     */
    public static function entryMatches(string $entry, string $query): bool
    {
        if ($query === '') {
            return false;
        }
        $common = $entry;
        $scientific = [];
        if (preg_match('/^(.*?)\((.*)\)\s*$/u', $entry, $parts)) {
            $common = $parts[1];
            $inner = trim($parts[2]);
            if (preg_match('/^[A-Z][a-z]+\s/u', $inner)) {
                $scientific = preg_match('/\s[xX×]\s/u', $inner) ? [$inner] : preg_split('/\s+e\s+|\s*,\s*/u', $inner);
            }
        } elseif (preg_match('/^[A-Z][a-z]+ (?:aff\. )?[a-z]+\.?$/u', $entry)) {
            $scientific = [$entry];
            $common = '';
        }

        foreach (preg_split('/\s*\/\s*|\s+ou\s+/iu', $common) as $alias) {
            $alias = self::normalize($alias);
            if ($alias !== '' && ($alias === $query || rtrim($alias, 's') === rtrim($query, 's'))) {
                return true;
            }
        }

        foreach ($scientific as $name) {
            $name = self::normalize($name);
            if ($name === $query) {
                return true;
            }
            if (preg_match('/^([a-z]+) spp\.?$/', $name, $genus)
                && preg_match('/^'.preg_quote($genus[1], '/').' [a-z]+/', $query)) {
                return true;
            }
        }

        return false;
    }
}
