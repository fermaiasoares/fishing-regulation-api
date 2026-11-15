<?php

namespace App\Legal;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Finds the legal areas that cover a point, including every ancestor area
 * (a point in "doce" is also in "leste" and "mg").
 */
final class LegalAreaResolver
{
    /**
     * @return array{geographic_match: bool, matched: list<string>, lineage: list<string>, areas: list<array{code: string, name: string}>}
     */
    public function at(float $latitude, float $longitude, int $catalogVersionId): array
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
            ->where('catalog_version_id', $catalogVersionId)
            ->get(['code', 'name', 'parent_code'])
            ->keyBy('code');

        $lineage = [];
        foreach ($matched as $code) {
            $visited = [];
            while ($code !== null && isset($areas[$code]) && ! isset($visited[$code])) {
                $visited[$code] = true;
                $lineage[$code] = true;
                $code = $areas[$code]->parent_code;
            }
        }
        $lineage = array_keys($lineage);

        return [
            'geographic_match' => $matched !== [],
            'matched' => $matched,
            'lineage' => $lineage,
            'areas' => array_map(fn (string $code) => ['code' => $code, 'name' => $areas[$code]->name], $lineage),
        ];
    }
}
