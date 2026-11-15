<?php

namespace Tests\Feature;

use App\Legal\CatalogImporter;
use App\Legal\SpeciesClassifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scenarios run against the repository catalog. The area footprints below are
 * synthetic squares for tests only; real footprints come from imported datasets.
 */
class FishingEvaluationTest extends TestCase
{
    use DatabaseTransactions;

    private const SQUARES = [
        'doce' => [10, 10],
        'sao_francisco' => [20, 20],
        'grande' => [30, 30],
        'leste' => [40, 40],
        'mg' => [5, 5],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        (new CatalogImporter)->import(config('legal.catalog_path'), publish: true);

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
                [$code, $versionId, "test-{$code}", $x, $y, $x + $size, $y + $size],
            );
        }
    }

    private function evaluate(string $area, string $species, string $date): array
    {
        [$x, $y] = self::SQUARES[$area];

        return $this->postJson('/api/fishing/evaluate', [
            'latitude' => $y + 0.5, 'longitude' => $x + 0.5, 'species' => $species, 'date' => $date,
        ])->assertOk()->json();
    }

    public function test_native_species_are_prohibited_all_year_in_the_doce_basin(): void
    {
        $result = $this->evaluate('doce', 'Prochilodus vimboides', '2027-06-15');

        $this->assertSame('prohibited', $result['status']);
        $this->assertSame('native', $result['species']['classification']);
        $this->assertSame(['doce', 'leste', 'mg'], array_column($result['location']['areas'], 'code'));
        $this->assertSame(['doce-nativas-permanente'], array_column($result['rules'], 'code'));
        $this->assertTrue($result['rules'][0]['verified']);
        $this->assertSame('ief-31-2025', $result['rules'][0]['act']['code']);
        $this->assertSame('art3', $result['rules'][0]['evidence'][0]['segment']);
        $this->assertNotEmpty($result['catalog']['checksum_sha256']);
    }

    public function test_non_natives_in_the_doce_piracema_combine_state_conditions(): void
    {
        $result = $this->evaluate('doce', 'tilápia', '2026-12-15');

        $this->assertSame('conditional', $result['status']);
        $this->assertSame(['doce-nao-nativas', 'leste-defeso-nao-nativas'], array_column($result['rules'], 'code'));
        $this->assertContains('limite de 3 kg + 1 exemplar por jornada (amador e profissional)', $result['conditions']);
        $this->assertContains('amador: 10 kg + 1 exemplar de qualquer tamanho acima do mínimo legal', $result['conditions']);
        $this->assertContains('leste-defeso-faixas', array_column($result['location_restrictions'], 'code'));
        $this->assertNotEmpty(array_filter($result['gaps'], fn ($gap) => str_contains($gap, 'restrições por local')));
    }

    public function test_sao_francisco_piracema_conditions_non_natives_and_covers_leap_day(): void
    {
        $pacu = $this->evaluate('sao_francisco', 'pacu', '2026-12-01');
        $this->assertSame('conditional', $pacu['status']);
        $this->assertContains('pesca profissional só para consumo familiar, sem comercialização', $pacu['conditions']);

        $leapDay = $this->evaluate('sao_francisco', 'tucunaré', '2028-02-29');
        $this->assertSame('conditional', $leapDay['status']);
        $this->assertSame(['sf-defeso-nao-nativas'], array_column($leapDay['rules'], 'code'));
        $this->assertSame('undetermined', $this->evaluate('sao_francisco', 'tucunaré', '2028-03-01')['status']);
    }

    public function test_species_listed_as_native_and_capturable_is_undetermined(): void
    {
        $result = $this->evaluate('sao_francisco', 'Pirambeba', '2026-12-15');

        $this->assertSame('undetermined', $result['status']);
        $this->assertSame(SpeciesClassifier::CONFLICTING, $result['species']['classification']);
        $this->assertSame([], $result['rules']);
    }

    public function test_unknown_species_is_undetermined_when_outcomes_differ(): void
    {
        $result = $this->evaluate('sao_francisco', 'Salminus brasiliensis', '2026-12-15');

        $this->assertSame('undetermined', $result['status']);
        $this->assertSame(SpeciesClassifier::UNKNOWN, $result['species']['classification']);
        $this->assertNotEmpty(array_filter($result['gaps'], fn ($gap) => str_contains($gap, 'não consta das listas')));
    }

    public function test_piaucu_is_prohibited_in_the_grande_piracema(): void
    {
        $result = $this->evaluate('grande', 'piauçu', '2027-01-10');

        $this->assertSame('prohibited', $result['status']);
        $this->assertSame(SpeciesClassifier::EXCLUDED, $result['species']['classification']);
        $this->assertSame(['gp-defeso-nao-nativas'], array_column($result['rules'], 'code'));
    }

    public function test_off_season_without_rules_is_undetermined_never_allowed(): void
    {
        foreach (['grande', 'leste', 'sao_francisco'] as $area) {
            $result = $this->evaluate($area, 'tucunaré', '2027-06-15');
            $this->assertSame('undetermined', $result['status'], $area);
            $this->assertNotEmpty($result['gaps']);
        }
    }

    public function test_a_point_outside_mapped_areas_is_undetermined(): void
    {
        $result = $this->postJson('/api/fishing/evaluate', [
            'latitude' => -80, 'longitude' => -170, 'species' => 'tilápia', 'date' => '2026-12-15',
        ])->assertOk()->json();

        $this->assertSame('undetermined', $result['status']);
        $this->assertFalse($result['location']['geographic_match']);
        $this->assertSame([], $result['rules']);
    }

    public function test_hybrid_parents_are_not_classified_by_the_cross(): void
    {
        $match = SpeciesClassifier::entryMatches(
            'Ponto e Vírgula ou Pintachara (Pseudoplatystoma corruscans X Pseudoplatystoma fasciatum)',
            SpeciesClassifier::normalize('Pseudoplatystoma corruscans'),
        );

        $this->assertFalse($match);
        $this->assertTrue(SpeciesClassifier::entryMatches('Tucunaré (Cichla spp.)', SpeciesClassifier::normalize('Cichla kelberi')));
        $this->assertFalse(SpeciesClassifier::entryMatches('Hypostomus sp.', SpeciesClassifier::normalize('Hypostomus garmani')));
        $this->assertTrue(SpeciesClassifier::entryMatches('Bagre-africano (Clarias sp.)', SpeciesClassifier::normalize('bagre africano')));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/fishing/evaluate', ['latitude' => 95, 'longitude' => 0, 'species' => 'x', 'date' => '15/12/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'species', 'date']);
    }

    public function test_without_a_published_catalog_the_api_refuses_to_answer(): void
    {
        DB::table('legal_catalog_versions')->update(['published_at' => null]);

        $this->postJson('/api/fishing/evaluate', ['latitude' => 10.5, 'longitude' => 10.5, 'species' => 'tilápia'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'catalog_unavailable');
    }
}
