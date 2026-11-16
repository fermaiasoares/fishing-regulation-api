<?php

namespace App\Legal;

use Illuminate\Support\Str;

/**
 * One entry per species (taxon) cited by the published catalogs: the names it
 * is written with, the lists that classify it and the threatened lists that
 * name it. Built from the species lists already imported, so it always matches
 * the catalog versions the API answers from.
 *
 * Entries are grouped by scientific name, after the list's spelling
 * corrections ("Cicla spp." and "Cichla spp." are one taxon). An entry with no
 * scientific name ("Tambacu", "Carpas (todas as espécies)") joins the one taxon
 * that already has that common name, or becomes a taxon of its own.
 */
final class SpeciesIndex
{
    public const RANK_SPECIES = 'species';

    /** "Cichla spp.": any species of the genus. */
    public const RANK_GENUS = 'genus';

    /** "Hypostomus sp.": one unidentified species of the genus. */
    public const RANK_UNIDENTIFIED = 'unidentified';

    public const RANK_HYBRID = 'hybrid';

    /** Named only by a common name, possibly a group ("Carpas (todas as espécies)"). */
    public const RANK_COMMON_NAME = 'common_name';

    /** @var array<string, array<string, array>> */
    private array $taxa = [];

    public function __construct(private readonly SpeciesClassifier $classifier) {}

    /**
     * Every taxon cited by the given catalog versions, keyed by id.
     *
     * @param  list<int>  $catalogVersionIds
     * @return array<string, array{id: string, name: string, scientific_name: ?string, rank: string, common_names: list<string>, written_as: list<string>, family: ?string, group: ?string, notes: list<string>, matches: list<array>, threatened: list<array>}>
     */
    public function all(array $catalogVersionIds): array
    {
        sort($catalogVersionIds);

        return $this->taxa[implode(',', $catalogVersionIds)] ??= $this->build($catalogVersionIds);
    }

    public function find(string $id, array $catalogVersionIds): ?array
    {
        return $this->all($catalogVersionIds)[$id] ?? null;
    }

    /**
     * Taxa cited by a list or threatened list that applies to the area lineage,
     * with only those citations kept.
     *
     * @param  list<string>  $areaLineage
     */
    public function forLineage(array $catalogVersionIds, array $areaLineage): array
    {
        $taxa = [];
        foreach ($this->all($catalogVersionIds) as $id => $taxon) {
            $taxon = self::restrict($taxon, $areaLineage);
            if ($taxon['matches'] !== [] || $taxon['threatened'] !== []) {
                $taxa[$id] = $taxon;
            }
        }

        return $taxa;
    }

    /** Keeps only the citations of lists that apply to the area lineage. */
    public static function restrict(array $taxon, array $areaLineage): array
    {
        $applies = fn (array $citation) => array_intersect($citation['areas'], $areaLineage) !== [];
        $taxon['matches'] = array_values(array_filter($taxon['matches'], $applies));
        $taxon['threatened'] = array_values(array_filter($taxon['threatened'], $applies));

        return $taxon;
    }

    /**
     * Taxa whose names contain the query, best matches first: exact name, then
     * a name or word starting with the query, then any substring. A binomial
     * that is not cited ("Cichla kelberi") also finds its genus entry ("Cichla spp.").
     *
     * @return list<array>
     */
    public function search(string $query, array $catalogVersionIds, int $limit): array
    {
        $query = SpeciesClassifier::normalize($query);
        if ($query === '') {
            return [];
        }
        $scored = [];
        foreach ($this->all($catalogVersionIds) as $taxon) {
            $best = null;
            foreach (self::names($taxon) as $name) {
                $name = SpeciesClassifier::normalize($name);
                $score = match (true) {
                    $name === $query || rtrim($name, 's') === rtrim($query, 's') => 0,
                    str_starts_with($name, $query) => 1,
                    str_contains(' '.$name, ' '.$query) => 2,
                    str_contains($name, $query) => 3,
                    default => null,
                };
                if ($score !== null && ($best === null || $score < $best)) {
                    $best = $score;
                }
            }
            if ($best === null && $taxon['rank'] === self::RANK_GENUS && $taxon['scientific_name'] !== null
                && preg_match('/^([a-z]+) [a-z]+/', $query, $genus)
                && str_starts_with(SpeciesClassifier::normalize($taxon['scientific_name']), $genus[1].' ')) {
                $best = 4;
            }
            if ($best !== null) {
                $scored[] = [$best, SpeciesClassifier::normalize($taxon['name']), $taxon];
            }
        }
        usort($scored, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(fn ($item) => $item[2], array_slice($scored, 0, $limit));
    }

    /** @return list<string> */
    private static function names(array $taxon): array
    {
        return array_values(array_filter([
            ...$taxon['common_names'],
            $taxon['scientific_name'],
            ...$taxon['written_as'],
        ]));
    }

    private function build(array $catalogVersionIds): array
    {
        $taxa = [];
        $commonOnly = [];
        foreach ($this->classifier->lists($catalogVersionIds) as $list) {
            $payload = $list['payload'];
            $citation = ['jurisdiction' => $list['jurisdiction'], 'list' => $list['code'], 'act' => $list['act'], 'areas' => $list['areas']];

            foreach ($payload['threatened'] ?? [] as $species) {
                $key = self::key($species['name']);
                $taxa[$key] ??= self::taxon($species['name'], self::rankOf($species['name'], false));
                $taxa[$key]['family'] ??= $species['family'] ?? null;
                $taxa[$key]['group'] ??= $species['group'] ?? null;
                $taxa[$key]['threatened'][] = $citation + [
                    'name' => $species['name'],
                    'category' => $species['category'],
                    'valid_from' => $payload['valid_from'] ?? null,
                ];
            }

            $valid = [];
            foreach ($payload['spelling'] ?? [] as $written => $correct) {
                $valid[SpeciesClassifier::normalize($written)] = $correct;
            }
            foreach (SpeciesClassifier::KEYS as $listKey => $classification) {
                foreach ($payload[$listKey] ?? [] as $entry) {
                    $parsed = SpeciesClassifier::parseEntry($entry);
                    $match = $citation + ['entry' => $entry, 'classification' => $classification];
                    if ($parsed['scientific'] === []) {
                        $commonOnly[] = [$parsed, $match];

                        continue;
                    }
                    foreach ($parsed['scientific'] as $written) {
                        $scientific = $valid[SpeciesClassifier::normalize($written)] ?? $written;
                        $key = self::key($scientific);
                        $taxa[$key] ??= self::taxon($scientific, self::rankOf($scientific, $parsed['hybrid']));
                        if (self::key($written) !== $key) {
                            $taxa[$key]['written_as'][] = $written;
                        }
                        self::addCommonNames($taxa[$key], $parsed['common']);
                        $taxa[$key]['matches'][] = $match;
                    }
                }
            }
        }

        foreach ($commonOnly as [$parsed, $match]) {
            $wanted = array_map(fn ($name) => rtrim(SpeciesClassifier::normalize($name), 's'), $parsed['common']);
            $owners = array_keys(array_filter($taxa, fn (array $taxon) => array_intersect(
                array_map(fn ($name) => rtrim(SpeciesClassifier::normalize($name), 's'), $taxon['common_names']),
                $wanted,
            ) !== []));
            if (count($owners) === 1) {
                $key = $owners[0];
            } else {
                $key = 'common:'.$wanted[0];
                $taxa[$key] ??= self::taxon(null, self::RANK_COMMON_NAME);
            }
            self::addCommonNames($taxa[$key], $parsed['common']);
            if ($parsed['note'] !== null && ! in_array($parsed['note'], $taxa[$key]['notes'], true)) {
                $taxa[$key]['notes'][] = $parsed['note'];
            }
            $taxa[$key]['matches'][] = $match;
        }

        // "Cichla spp." covers every species of the genus, as in SpeciesClassifier::entryMatches.
        foreach ($taxa as $key => $taxon) {
            if ($taxon['rank'] !== self::RANK_SPECIES) {
                continue;
            }
            $genus = strtok($key, ' ');
            foreach ($taxa["{$genus} spp."]['matches'] ?? [] as $match) {
                $taxa[$key]['matches'][] = $match;
            }
        }

        $byId = [];
        foreach ($taxa as $taxon) {
            $taxon['written_as'] = array_values(array_unique($taxon['written_as']));
            $taxon['name'] = $taxon['common_names'][0] ?? $taxon['scientific_name'];
            $base = Str::slug($taxon['scientific_name'] ?? $taxon['name']);
            $id = $base;
            for ($n = 2; isset($byId[$id]); $n++) {
                $id = "{$base}-{$n}";
            }
            $byId[$id] = ['id' => $id] + $taxon;
        }
        ksort($byId);

        return $byId;
    }

    /** Grouping key of a scientific name: "Hoplosternum sp" and "Hoplosternum sp." are one taxon. */
    private static function key(string $scientific): string
    {
        return preg_replace('/ (spp?)$/', ' $1.', SpeciesClassifier::normalize($scientific));
    }

    private static function taxon(?string $scientific, string $rank): array
    {
        return [
            'name' => null,
            'scientific_name' => $scientific,
            'rank' => $rank,
            'common_names' => [],
            'written_as' => [],
            'family' => null,
            'group' => null,
            'notes' => [],
            'matches' => [],
            'threatened' => [],
        ];
    }

    private static function rankOf(string $scientific, bool $hybrid): string
    {
        return match (true) {
            $hybrid => self::RANK_HYBRID,
            preg_match('/ spp\.?$/', $scientific) === 1 => self::RANK_GENUS,
            preg_match('/ sp\.?$/', $scientific) === 1 => self::RANK_UNIDENTIFIED,
            default => self::RANK_SPECIES,
        };
    }

    /** Adds common names not yet present, compared without accents, case or plural "s". */
    private static function addCommonNames(array &$taxon, array $names): void
    {
        $seen = array_map(fn ($name) => rtrim(SpeciesClassifier::normalize($name), 's'), $taxon['common_names']);
        foreach ($names as $name) {
            $key = rtrim(SpeciesClassifier::normalize($name), 's');
            if ($key !== '' && ! in_array($key, $seen, true)) {
                $taxon['common_names'][] = $name;
                $seen[] = $key;
            }
        }
    }
}
