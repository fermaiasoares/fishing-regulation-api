<?php

namespace Tests\Feature;

use App\Geography\RiverLookup;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RiverLookupTest extends TestCase
{
    use DatabaseTransactions;

    /** Two synthetic rivers 0.01° apart (about 1.1 km) along parallels near the equator. */
    private function segments(string $version = 'v1'): int
    {
        $datasetId = DB::table('geographic_datasets')->where('slug', RiverLookup::DATASET)->value('id')
            ?? DB::table('geographic_datasets')->insertGetId([
                'slug' => RiverLookup::DATASET, 'name' => 'BHAE (test)', 'publisher' => 'tests',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        $versionId = DB::table('geographic_dataset_versions')->insertGetId([
            'dataset_id' => $datasetId, 'version' => $version, 'source_url' => 'test://bhae',
            'checksum_sha256' => str_repeat('1', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([['1', 'Rio Federal', 'federal', 0.0], ['2', 'Rio Estadual', 'state', 0.01]] as [$id, $name, $domain, $lat]) {
            DB::insert(
                'INSERT INTO river_segments (dataset_version_id, source_feature_id, name, river_code, domain, geometry, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ST_Multi(ST_MakeLine(ST_MakePoint(0, ?), ST_MakePoint(1, ?))), now(), now())',
                [$versionId, "{$version}-{$id}", $name, "77{$id}", $domain, $lat, $lat],
            );
        }

        return $versionId;
    }

    public function test_it_returns_the_nearest_segment_with_its_distance(): void
    {
        $this->segments();
        $lookup = new RiverLookup;

        $onFederal = $lookup->nearest(0.0, 0.5, 2000);
        $this->assertSame('Rio Federal', $onFederal['name']);
        $this->assertSame('federal', $onFederal['domain']);
        $this->assertSame(0, $onFederal['distance_m']);

        $nearState = $lookup->nearest(0.008, 0.5, 2000);
        $this->assertSame('Rio Estadual', $nearState['name']);
        $this->assertEqualsWithDelta(221, $nearState['distance_m'], 2);
    }

    public function test_nothing_is_returned_beyond_the_search_radius(): void
    {
        $this->segments();

        $this->assertNull((new RiverLookup)->nearest(0.05, 0.5, 2000));
    }

    public function test_only_the_latest_loaded_version_is_used(): void
    {
        $this->segments('v1');
        $this->segments('v2');

        $this->assertSame('v2-1', (new RiverLookup)->nearest(0.0, 0.5, 2000)['segment']);
    }
}
