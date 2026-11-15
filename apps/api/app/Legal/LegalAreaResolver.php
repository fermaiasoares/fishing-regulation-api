<?php

namespace App\Legal;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finds the legal areas that cover a point, across jurisdictions.
 *
 * A point mapped to "mg:doce" is also in its parents ("mg:leste", "mg") and in
 * the areas of other jurisdictions those areas lie within ("br:leste", "br"),
 * so federal basin rules reach it without being copied into each state catalog
 * (docs/adr/0001-jurisdicoes.md).
 */
final class LegalAreaResolver
{
    public const FEDERAL = 'br';

    /**
     * @param  list<int>  $catalogVersionIds  published version of each jurisdiction
     * @return array{geographic_match: bool, matched: list<string>, unpublished: list<string>, states: list<string>, jurisdictions: list<string>, lineage: list<string>, areas: list<array{code: string, name: string, jurisdiction: string}>}
     */
    public function at(float $latitude, float $longitude, array $catalogVersionIds): array
    {
        if (! is_finite($latitude) || $latitude < -90 || $latitude > 90
            || ! is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Latitude or longitude outside WGS 84 bounds.');
        }

        $matched = DB::table('legal_area_geometries')
            ->whereRaw('ST_Covers(geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))', [$longitude, $latitude])
            ->distinct()
            ->orderBy('legal_area_code')
            ->pluck('legal_area_code')
            ->all();

        $areas = DB::table('legal_areas')
            ->whereIn('catalog_version_id', $catalogVersionIds)
            ->get(['code', 'name', 'parent_code', 'within_codes'])
            ->keyBy('code');

        // Breadth-first: an area's own parents first, then areas of other
        // jurisdictions reached through "within", most specific first.
        $lineage = [];
        $pending = array_values(array_filter($matched, fn (string $code) => isset($areas[$code])));
        $depth = function (string $code) use ($areas): int {
            $levels = 0;
            for ($seen = []; isset($areas[$code]) && $areas[$code]->parent_code !== null && ! isset($seen[$code]); $levels++) {
                $seen[$code] = true;
                $code = $areas[$code]->parent_code;
            }

            return $levels;
        };
        usort($pending, fn (string $a, string $b) => [$depth($b), $a] <=> [$depth($a), $b]);
        while ($pending !== []) {
            $code = array_shift($pending);
            $chain = [];
            while ($code !== null && isset($areas[$code]) && ! isset($lineage[$code])) {
                $lineage[$code] = true;
                $chain[] = $code;
                $code = $areas[$code]->parent_code;
            }
            foreach ($chain as $member) {
                foreach (json_decode($areas[$member]->within_codes ?? '[]', true) as $outer) {
                    $pending[] = $outer;
                }
            }
        }
        $lineage = array_keys($lineage);

        $jurisdictions = array_values(array_unique(array_map([self::class, 'jurisdictionOf'], $lineage)));
        sort($jurisdictions);
        $states = array_values(array_unique(array_filter(
            array_map([self::class, 'jurisdictionOf'], $matched),
            fn (string $jurisdiction) => $jurisdiction !== self::FEDERAL,
        )));
        sort($states);

        return [
            'geographic_match' => $matched !== [],
            'matched' => $matched,
            'unpublished' => array_values(array_filter($matched, fn (string $code) => ! isset($areas[$code]))),
            'states' => $states,
            'jurisdictions' => $jurisdictions,
            'lineage' => $lineage,
            'areas' => array_map(fn (string $code) => [
                'code' => $code,
                'name' => $areas[$code]->name,
                'jurisdiction' => self::jurisdictionOf($code),
            ], $lineage),
        ];
    }

    /** "mg:doce" -> "mg"; "br" -> "br". */
    public static function jurisdictionOf(string $areaCode): string
    {
        return explode(':', $areaCode, 2)[0];
    }
}
