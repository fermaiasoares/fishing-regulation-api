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
 *
 * Districts map to areas of one state catalog. The jurisdiction comes from the
 * manifest or, failing that, from the directory name (catalog/geo/mg), and the
 * stored codes are qualified ("mg:doce") like the catalog's (docs/adr/0001-jurisdicoes.md).
 */
final class LegalAreaImporter
{
    public const DATA_FILE = 'circunscricoes_hidrograficas.geojson.gz';

    /** The decompressed file is ~41 MB; it is passed to PostgreSQL as one string. */
    private const MEMORY_LIMIT = '768M';

    /**
     * @return array{dataset_version_id: int, created: bool, features: int, repaired: list<string>, by_area: array<string, int>}
     */
    public function import(string $directory): array
    {
        if (self::bytes((string) ini_get('memory_limit')) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }
        $manifest = $this->readJson($directory.'/manifest.json');
        $raw = @gzdecode((string) @file_get_contents($directory.'/'.self::DATA_FILE));
        if ($raw === false || $raw === '') {
            throw new InvalidArgumentException('Data file missing or not gzip: '.$directory.'/'.self::DATA_FILE);
        }
        if (! hash_equals((string) ($manifest['sha256'] ?? ''), hash('sha256', $raw))) {
            throw new InvalidArgumentException('Data file does not match the SHA-256 in the manifest.');
        }
        // Identity of the dataset version: the content hash ignores the volatile
        // timeStamp GeoServer adds to every response; older manifests lack it.
        $versionChecksum = (string) ($manifest['content_sha256'] ?? $manifest['sha256']);
        $jurisdiction = (string) ($manifest['jurisdiction'] ?? basename(rtrim($directory, '/')));
        if (preg_match('/^[a-z]{2}$/', $jurisdiction) !== 1) {
            throw new InvalidArgumentException("Manifest has no jurisdiction and the directory name \"{$jurisdiction}\" is not one.");
        }
        $areas = array_map(
            fn (string $area) => $area === $jurisdiction ? $area : "{$jurisdiction}:{$area}",
            array_column($manifest['districts'] ?? [], 'area', 'sigla'),
        );
        if ($areas === []) {
            throw new InvalidArgumentException('Manifest lists no districts.');
        }
        $srid = (int) ($manifest['source_srid'] ?? 0);
        if ($srid !== 4674 && $srid !== 4326) {
            throw new InvalidArgumentException("Unsupported source SRID {$srid}.");
        }

        return DB::transaction(function () use ($manifest, $raw, $areas, $srid, $versionChecksum) {
            $datasetId = $this->datasetId($manifest);
            $existing = DB::table('geographic_dataset_versions')
                ->where('dataset_id', $datasetId)->where('checksum_sha256', $versionChecksum)->value('id');
            // A version loaded before feature names were stored is loaded again to fill them.
            if ($existing !== null
                && DB::table('legal_area_geometries')->where('dataset_version_id', $existing)->exists()
                && ! DB::table('legal_area_geometries')->where('dataset_version_id', $existing)->whereNull('source_feature_name')->exists()) {
                return ['dataset_version_id' => (int) $existing, 'created' => false, 'features' => 0, 'repaired' => [], 'by_area' => []];
            }

            // The file (tens of MB) is parsed by PostgreSQL, not decoded in PHP.
            DB::statement('DROP TABLE IF EXISTS legal_area_import');
            DB::statement(<<<'SQL'
                CREATE TEMPORARY TABLE legal_area_import ON COMMIT DROP AS
                WITH features AS (SELECT json_array_elements((?::json)->'features') AS feature)
                SELECT upper(trim(coalesce(feature->'properties'->>'sigla', feature->'properties'->>'SIGLA'))) AS sigla,
                       nullif(trim(coalesce(feature->'properties'->>'nome', feature->'properties'->>'NOME')), '') AS nome,
                       ST_SetSRID(ST_GeomFromGeoJSON(feature->>'geometry'), ?) AS geometry
                FROM features
                SQL, [$raw, $srid]);

            $siglas = DB::table('legal_area_import')->pluck('sigla')->all();
            sort($siglas);
            $expected = array_keys($areas);
            sort($expected);
            if ($siglas !== $expected) {
                throw new InvalidArgumentException('Districts in the data file do not match the manifest.');
            }

            $versionId = $existing ?? DB::table('geographic_dataset_versions')->insertGetId([
                'dataset_id' => $datasetId,
                'version' => substr($manifest['retrieved_at'], 0, 10).'-'.substr($versionChecksum, 0, 12),
                'source_url' => $manifest['url'],
                'collected_at' => $manifest['retrieved_at'],
                'license' => $manifest['license'] ?? null,
                'checksum_sha256' => $versionChecksum,
                'source_crs' => "EPSG:{$srid}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('legal_area_geometries')
                ->whereIn('dataset_version_id', DB::table('geographic_dataset_versions')->where('dataset_id', $datasetId)->select('id'))
                ->delete();

            $repaired = DB::table('legal_area_import')->whereRaw('NOT ST_IsValid(geometry)')->orderBy('sigla')->pluck('sigla')->all();
            DB::insert(<<<'SQL'
                INSERT INTO legal_area_geometries (legal_area_code, dataset_version_id, source_feature_id, source_feature_name, geometry, created_at, updated_at)
                SELECT mapping.value, ?, import.sigla, coalesce(import.nome, import.sigla),
                       ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_Transform(import.geometry, 4326)), 3)), now(), now()
                FROM legal_area_import AS import
                JOIN json_each_text(?::json) AS mapping ON mapping.key = import.sigla
                SQL, [$versionId, json_encode($areas)]);

            $byArea = array_count_values($areas);
            ksort($byArea);

            return [
                'dataset_version_id' => (int) $versionId,
                'created' => true,
                'features' => count($siglas),
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

    private static function bytes(string $value): int
    {
        if ($value === '-1') {
            return PHP_INT_MAX;
        }
        $number = (int) $value;

        return match (strtoupper(substr(trim($value), -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }

    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Manifest not found: {$path}");
        }

        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }
}
