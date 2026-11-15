<?php

namespace App\Geography;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Loads the hydrographic districts acquired by services/geo-ingestion/legal_areas.py
 * into legal_area_geometries.
 *
 * The stored file is checked against the SHA-256 in its manifest. Geometries are
 * read in the source CRS (SIRGAS 2000), repaired with ST_MakeValid when needed and
 * transformed to EPSG:4326 inside PostGIS. A dataset version is imported once; a
 * new version replaces the footprints of the previous one in the same transaction.
 */
final class LegalAreaImporter
{
    public const DATA_FILE = 'circunscricoes_hidrograficas.geojson.gz';

    /**
     * @return array{dataset_version_id: int, created: bool, features: int, repaired: list<string>, by_area: array<string, int>}
     */
    public function import(string $directory): array
    {
        $manifest = $this->readJson($directory.'/manifest.json');
        $raw = @gzdecode((string) @file_get_contents($directory.'/'.self::DATA_FILE));
        if ($raw === false || $raw === '') {
            throw new InvalidArgumentException('Data file missing or not gzip: '.$directory.'/'.self::DATA_FILE);
        }
        if (! hash_equals((string) ($manifest['sha256'] ?? ''), hash('sha256', $raw))) {
            throw new InvalidArgumentException('Data file does not match the SHA-256 in the manifest.');
        }
        $collection = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $areas = array_column($manifest['districts'] ?? [], 'area', 'sigla');
        if ($areas === [] || count($collection['features'] ?? []) !== count($areas)) {
            throw new InvalidArgumentException('Manifest districts do not match the data file.');
        }
        $srid = (int) ($manifest['source_srid'] ?? 0);
        if ($srid !== 4674 && $srid !== 4326) {
            throw new InvalidArgumentException("Unsupported source SRID {$srid}.");
        }

        return DB::transaction(function () use ($manifest, $collection, $areas, $srid) {
            $datasetId = $this->datasetId($manifest);
            $existing = DB::table('geographic_dataset_versions')
                ->where('dataset_id', $datasetId)->where('checksum_sha256', $manifest['sha256'])->value('id');
            if ($existing !== null && DB::table('legal_area_geometries')->where('dataset_version_id', $existing)->exists()) {
                return ['dataset_version_id' => (int) $existing, 'created' => false, 'features' => 0, 'repaired' => [], 'by_area' => []];
            }
            $versionId = $existing ?? DB::table('geographic_dataset_versions')->insertGetId([
                'dataset_id' => $datasetId,
                'version' => substr($manifest['retrieved_at'], 0, 10).'-'.substr($manifest['sha256'], 0, 12),
                'source_url' => $manifest['url'],
                'collected_at' => $manifest['retrieved_at'],
                'license' => $manifest['license'] ?? null,
                'checksum_sha256' => $manifest['sha256'],
                'source_crs' => "EPSG:{$srid}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('legal_area_geometries')
                ->whereIn('dataset_version_id', DB::table('geographic_dataset_versions')->where('dataset_id', $datasetId)->select('id'))
                ->delete();

            $repaired = [];
            $byArea = [];
            foreach ($collection['features'] as $feature) {
                $properties = array_change_key_case($feature['properties'] ?? []);
                $sigla = strtoupper(trim((string) ($properties['sigla'] ?? '')));
                if (! isset($areas[$sigla])) {
                    throw new InvalidArgumentException("District {$sigla} is not in the manifest.");
                }
                $row = DB::selectOne(<<<'SQL'
                    WITH source AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?), ?) AS geometry)
                    INSERT INTO legal_area_geometries (legal_area_code, dataset_version_id, source_feature_id, geometry, created_at, updated_at)
                    SELECT ?, ?, ?, ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(geometry, 4326)), 3)), now(), now()
                    FROM source
                    RETURNING (SELECT NOT ST_IsValid(geometry) FROM source) AS repaired
                    SQL, [json_encode($feature['geometry']), $srid, $areas[$sigla], $versionId, $sigla]);
                if ($row->repaired) {
                    $repaired[] = $sigla;
                }
                $byArea[$areas[$sigla]] = ($byArea[$areas[$sigla]] ?? 0) + 1;
            }
            ksort($byArea);

            return [
                'dataset_version_id' => (int) $versionId,
                'created' => true,
                'features' => count($collection['features']),
                'repaired' => $repaired,
                'by_area' => $byArea,
            ];
        });
    }

    private function datasetId(array $manifest): int
    {
        $slug = $manifest['dataset'] ?? throw new InvalidArgumentException('Manifest has no dataset slug.');
        $id = DB::table('geographic_datasets')->where('slug', $slug)->value('id');

        return (int) ($id ?? DB::table('geographic_datasets')->insertGetId([
            'slug' => $slug,
            'name' => 'Circunscrições Hidrográficas de Minas Gerais',
            'publisher' => $manifest['publisher'] ?? 'Igam / IDE-Sisema',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Manifest not found: {$path}");
        }

        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
}
