<?php

namespace Tests\Feature;

use App\Legal\CatalogImporter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class LegalCatalogImportTest extends TestCase
{
    use DatabaseTransactions;

    private array $catalog;

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalog = json_decode(file_get_contents(config('legal.catalog_path')), true, 64, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->temporaryFiles, 'is_file'));
        parent::tearDown();
    }

    public function test_it_imports_the_repository_catalog_with_its_evidence(): void
    {
        $result = (new CatalogImporter)->import(config('legal.catalog_path'));

        $this->assertTrue($result['created']);
        $version = DB::table('legal_catalog_versions')->find($result['version_id']);
        $this->assertSame(count($this->catalog['acts']), $version->act_count);
        $this->assertSame(count($this->catalog['rules']), DB::table('legal_rules')->where('catalog_version_id', $version->id)->count());
        $this->assertSame(
            $this->catalog['verified_rules'],
            DB::table('legal_rules')->where('catalog_version_id', $version->id)->where('verified', true)->orderBy('code')->pluck('code')->all(),
        );

        $doce = DB::table('legal_rules')->where('catalog_version_id', $version->id)->where('code', 'doce-nativas-permanente')->first();
        $this->assertSame(['permanent', 'native', 'prohibited'], [$doce->period_kind, $doce->species_scope, $doce->effect]);
        $this->assertSame('ief-31-2025', DB::table('legal_acts')->where('id', $doce->legal_act_id)->value('code'));
        $this->assertSame(['doce'], DB::table('legal_rule_areas')
            ->join('legal_areas', 'legal_areas.id', '=', 'legal_rule_areas.legal_area_id')
            ->where('legal_rule_id', $doce->id)->pluck('legal_areas.code')->all());
        $this->assertStringContainsString(
            'vedada a pesca de espécies autóctones',
            DB::table('legal_rule_evidence')->where('legal_rule_id', $doce->id)->orderBy('position')->value('quote'),
        );
    }

    public function test_reimporting_the_same_file_creates_nothing(): void
    {
        $importer = new CatalogImporter;
        $first = $importer->import(config('legal.catalog_path'));
        $rules = DB::table('legal_rules')->count();

        $second = $importer->import(config('legal.catalog_path'));

        $this->assertFalse($second['created']);
        $this->assertSame($first['version_id'], $second['version_id']);
        $this->assertSame($rules, DB::table('legal_rules')->count());
    }

    public function test_a_changed_catalog_is_a_new_version_and_only_published_versions_are_current(): void
    {
        $importer = new CatalogImporter;
        $original = $importer->import(config('legal.catalog_path'), publish: true);
        $this->assertSame($original['version_id'], CatalogImporter::currentVersionId('mg'));

        $changed = $this->catalog;
        $changed['rules'][0]['summary'] .= ' (revisado)';
        $revision = $importer->import($this->writeCatalog($changed));

        $this->assertTrue($revision['created']);
        $this->assertNotSame($original['version_id'], $revision['version_id']);
        $this->assertSame($original['version_id'], CatalogImporter::currentVersionId('mg'));

        $this->travel(1)->seconds();
        $importer->import($this->writeCatalog($changed), publish: true);
        $this->assertSame($revision['version_id'], CatalogImporter::currentVersionId('mg'));
    }

    public function test_inconsistent_catalogs_are_rejected_before_writing(): void
    {
        $revokedAct = $this->catalog;
        $revokedAct['rules'][0]['act'] = 'ief-40-2017';
        $unknownArea = $this->catalog;
        $unknownArea['rules'][0]['basins'] = ['atlantida'];

        foreach ([$revokedAct, $unknownArea, ['format' => 'other']] as $catalog) {
            try {
                (new CatalogImporter)->import($this->writeCatalog($catalog));
                $this->fail('An inconsistent catalog was imported');
            } catch (InvalidArgumentException) {
                $this->assertSame(0, DB::table('legal_catalog_versions')->count());
            }
        }
    }

    public function test_the_database_never_stores_a_permission(): void
    {
        $catalog = $this->catalog;
        $catalog['rules'][0]['effect'] = 'allowed';

        $this->expectException(QueryException::class);
        (new CatalogImporter)->import($this->writeCatalog($catalog));
    }

    public function test_the_artisan_command_imports_and_reports_reimports(): void
    {
        $this->artisan('legal:import', ['--publish' => true])
            ->expectsOutputToContain('Imported catalog version')
            ->assertSuccessful();
        $this->artisan('legal:import')
            ->expectsOutputToContain('Catalog already imported')
            ->assertSuccessful();
        $this->artisan('legal:import', ['path' => '/nonexistent/catalog.json'])
            ->expectsOutputToContain('Catalog file not found')
            ->assertFailed();
    }

    private function writeCatalog(array $catalog): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legal-catalog-');
        $this->temporaryFiles[] = $path;
        file_put_contents($path, json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $path;
    }
}
