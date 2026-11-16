<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Publishes the repository catalogs and maps synthetic 1° squares to MG areas.
 * The squares are for tests only; real footprints come from imported datasets.
 */
trait SyntheticLegalAreas
{
    protected const SQUARES = [
        'doce' => [10, 10],
        'sao_francisco' => [20, 20],
        'grande' => [30, 30],
        'leste' => [40, 40],
        'mg' => [5, 5],
    ];

    protected function setUpSyntheticLegalAreas(): void
    {
        $this->importLegalCatalogs();

        $datasetId = DB::table('geographic_datasets')->insertGetId([
            'slug' => 'test-legal-areas', 'name' => 'Synthetic legal areas', 'publisher' => 'tests',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = DB::table('geographic_dataset_versions')->insertGetId([
            'dataset_id' => $datasetId, 'version' => 'test', 'source_url' => 'test://synthetic',
            'checksum_sha256' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (self::SQUARES as $code => [$x, $y]) {
            $size = $code === 'mg' ? 40 : 1;
            DB::insert(
                'INSERT INTO legal_area_geometries (legal_area_code, dataset_version_id, source_feature_id, geometry, created_at, updated_at)
                 VALUES (?, ?, ?, ST_Multi(ST_MakeEnvelope(?, ?, ?, ?, 4326)), now(), now())',
                [$code === 'mg' ? 'mg' : "mg:{$code}", $versionId, "test-{$code}", $x, $y, $x + $size, $y + $size],
            );
        }
    }

    /** @return array{latitude: float, longitude: float} */
    protected function pointIn(string $area): array
    {
        [$x, $y] = self::SQUARES[$area];

        return ['latitude' => $y + 0.5, 'longitude' => $x + 0.5];
    }
}
