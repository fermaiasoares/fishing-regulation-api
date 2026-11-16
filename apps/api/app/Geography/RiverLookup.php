<?php

namespace App\Geography;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Nearest drainage segment of the ANA BHAE to a point (services/geo-ingestion/bhae.py).
 *
 * A point chosen on a map rarely falls exactly on a river's centre line, and a
 * reservoir can be more than a kilometre wide, so the lookup returns the nearest
 * segment with its distance. The caller decides how close is close enough.
 */
final class RiverLookup
{
    public const DATASET = 'ana-bhae-trecho-drenagem';

    /**
     * @return array{name: ?string, code: ?string, domain: ?string, segment: string, distance_m: int, source: string}|null
     */
    public function nearest(float $latitude, float $longitude, int $withinMeters): ?array
    {
        if (! is_finite($latitude) || $latitude < -90 || $latitude > 90
            || ! is_finite($longitude) || $longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('Latitude or longitude outside WGS 84 bounds.');
        }

        $version = $this->currentVersion();
        if ($version === null) {
            return null;
        }

        // KNN on the GiST index picks a few candidates in degrees; the distance in
        // metres is then measured on the spheroid and decides among them. The point
        // must be written inline: through a CTE the planner falls back to a full scan.
        $row = DB::selectOne(<<<'SQL'
            SELECT c.source_feature_id, c.name, c.river_code, c.domain,
                   ST_Distance(c.geometry::geography, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS distance
            FROM (
                SELECT r.source_feature_id, r.name, r.river_code, r.domain, r.geometry
                FROM river_segments r
                WHERE r.dataset_version_id = ?
                ORDER BY r.geometry <-> ST_SetSRID(ST_MakePoint(?, ?), 4326)
                LIMIT 8
            ) AS c
            ORDER BY distance
            LIMIT 1
            SQL, [$longitude, $latitude, $version, $longitude, $latitude]);

        if ($row === null || $row->distance > $withinMeters) {
            return null;
        }

        return [
            'name' => $row->name,
            'code' => $row->river_code,
            'domain' => $row->domain,
            'segment' => $row->source_feature_id,
            'distance_m' => (int) round($row->distance),
            'source' => 'ANA, Base Hidrográfica Atlas-Estudos (BHAE)',
        ];
    }

    /** Latest version of the dataset that has segments loaded (bhae.py keeps one at a time). */
    private function currentVersion(): ?int
    {
        $id = DB::table('geographic_dataset_versions as v')
            ->join('geographic_datasets as d', 'd.id', '=', 'v.dataset_id')
            ->where('d.slug', self::DATASET)
            ->orderByDesc('v.id')
            ->pluck('v.id')
            ->first(fn (int $id) => DB::table('river_segments')->where('dataset_version_id', $id)->limit(1)->exists());

        return $id === null ? null : (int) $id;
    }
}
