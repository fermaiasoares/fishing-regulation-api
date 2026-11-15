<?php

namespace Tests\Feature;

use App\Geography\LegalAreaImporter;
use App\Legal\CatalogImporter;
use App\Legal\LegalAreaResolver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class LegalAreaImportTest extends TestCase
{
    use DatabaseTransactions;

    private array $directories = [];

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            array_map('unlink', glob($directory.'/*'));
            rmdir($directory);
        }
        parent::tearDown();
    }

    /** Synthetic fixture: two squares in SIRGAS 2000, one valid and one self-intersecting. */
    private function fixture(string $retrievedAt = '2026-11-15T00:00:00+00:00', float $offset = 0): string
    {
        $collection = ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => ['SIGLA' => 'DO1', 'NOME' => 'Teste A'], 'geometry' => [
                'type' => 'Polygon', 'coordinates' => [[[10 + $offset, 10], [11 + $offset, 10], [11 + $offset, 11], [10 + $offset, 11], [10 + $offset, 10]]],
            ]],
            ['type' => 'Feature', 'properties' => ['SIGLA' => 'SF3', 'NOME' => 'Teste B'], 'geometry' => [
                'type' => 'Polygon', 'coordinates' => [[[20, 20], [21, 21], [21, 20], [20, 21], [20, 20]]],
            ]],
        ]];
        $raw = json_encode($collection);
        $directory = sys_get_temp_dir().'/legal-areas-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $this->directories[] = $directory;
        file_put_contents($directory.'/'.LegalAreaImporter::DATA_FILE, gzencode($raw));
        file_put_contents($directory.'/manifest.json', json_encode([
            'dataset' => 'test-districts', 'publisher' => 'tests', 'url' => 'test://synthetic', 'license' => null,
            'source_srid' => 4674, 'sha256' => hash('sha256', $raw), 'retrieved_at' => $retrievedAt,
            'districts' => [['sigla' => 'DO1', 'area' => 'doce'], ['sigla' => 'SF3', 'area' => 'paraopeba']],
        ]));

        return $directory;
    }

    public function test_it_imports_repairs_and_transforms_districts_once(): void
    {
        $directory = $this->fixture();
        $result = (new LegalAreaImporter)->import($directory);

        $this->assertTrue($result['created']);
        $this->assertSame(['doce' => 1, 'paraopeba' => 1], $result['by_area']);
        $this->assertSame(['SF3'], $result['repaired']);
        $row = DB::selectOne('SELECT bool_and(ST_IsValid(geometry)) AS valid, min(ST_SRID(geometry)) AS srid FROM legal_area_geometries WHERE dataset_version_id = ?', [$result['dataset_version_id']]);
        $this->assertTrue($row->valid);
        $this->assertSame(4326, $row->srid);

        $again = (new LegalAreaImporter)->import($directory);
        $this->assertFalse($again['created']);
        $this->assertSame(2, DB::table('legal_area_geometries')->count());
    }

    public function test_a_new_version_replaces_the_previous_footprints(): void
    {
        (new LegalAreaImporter)->import($this->fixture());
        $second = (new LegalAreaImporter)->import($this->fixture('2026-12-01T00:00:00+00:00', 0.5));

        $this->assertTrue($second['created']);
        $this->assertSame([$second['dataset_version_id']], DB::table('legal_area_geometries')->distinct()->pluck('dataset_version_id')->all());
        $this->assertSame(2, DB::table('geographic_dataset_versions')->count());
    }

    public function test_tampered_data_is_rejected(): void
    {
        $directory = $this->fixture();
        file_put_contents($directory.'/'.LegalAreaImporter::DATA_FILE, gzencode('{"type":"FeatureCollection","features":[]}'));

        $this->expectException(InvalidArgumentException::class);
        (new LegalAreaImporter)->import($directory);
    }

    /**
     * Uses the official districts committed in catalog/geo/mg. City coordinates are
     * approximate town centres, far from basin divides.
     */
    public function test_official_districts_resolve_known_towns_to_their_legal_areas(): void
    {
        $directory = config('legal.geo_path');
        if (! is_file($directory.'/manifest.json')) {
            $this->markTestSkipped('Official districts not acquired yet.');
        }
        $catalog = (new CatalogImporter)->import(config('legal.catalog_path'), publish: true);
        $result = (new LegalAreaImporter)->import($directory);
        $this->assertGreaterThanOrEqual(36, $result['features'] ?: DB::table('legal_area_geometries')->count());

        $resolver = new LegalAreaResolver;
        $towns = [
            'Caratinga' => [-19.7895, -42.1393, 'doce'],
            'Brumadinho' => [-20.1430, -44.2000, 'paraopeba'],
            'Belo Horizonte' => [-19.9167, -43.9345, 'sao_francisco'],
            'Montes Claros' => [-16.7350, -43.8617, 'sao_francisco'],
            'Uberaba' => [-19.7472, -47.9381, 'grande'],
            'Patos de Minas' => [-18.5789, -46.5181, 'paranaiba'],
            'Teófilo Otoni' => [-17.8575, -41.5053, 'leste'],
            'Juiz de Fora' => [-21.7642, -43.3496, 'leste'],
        ];
        foreach ($towns as $town => [$latitude, $longitude, $area]) {
            $match = $resolver->at($latitude, $longitude, $catalog['version_id']);
            $this->assertSame([$area], $match['matched'], $town);
            $this->assertContains('mg', $match['lineage'], $town);
        }
        $this->assertFalse($resolver->at(-23.5505, -46.6333, $catalog['version_id'])['geographic_match'], 'São Paulo is outside MG');

        $this->postJson('/api/fishing/evaluate', [
            'latitude' => -19.7895, 'longitude' => -42.1393, 'species' => 'Pimelodus maculatus', 'date' => '2027-06-15',
        ])->assertOk()->assertJsonPath('status', 'prohibited')->assertJsonPath('rules.0.code', 'doce-nativas-permanente');
    }
}
