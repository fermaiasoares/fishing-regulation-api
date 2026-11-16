<?php

namespace Tests\Feature;

use App\Legal\SpeciesClassifier;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SyntheticLegalAreas;
use Tests\TestCase;

/**
 * Scenarios run against the repository catalog, on synthetic area footprints.
 */
class FishingEvaluationTest extends TestCase
{
    use DatabaseTransactions;
    use SyntheticLegalAreas;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSyntheticLegalAreas();
    }

    private function evaluate(string $area, string $species, string $date): array
    {
        return $this->postJson('/api/fishing/evaluate', $this->pointIn($area) + [
            'species' => $species, 'date' => $date,
        ])->assertOk()->json();
    }

    public function test_native_species_are_prohibited_all_year_in_the_doce_basin(): void
    {
        $result = $this->evaluate('doce', 'Pimelodus maculatus', '2027-06-15');

        $this->assertSame('prohibited', $result['status']);
        $this->assertSame('native', $result['species']['classification']);
        $this->assertSame([], $result['species']['threatened']);
        $this->assertSame(['mg:doce', 'mg:leste', 'mg', 'br:leste', 'br'], array_column($result['location']['areas'], 'code'));
        $this->assertSame(['doce-nativas-permanente'], array_column($result['rules'], 'code'));
        $this->assertTrue($result['rules'][0]['verified']);
        $this->assertSame('ief-31-2025', $result['rules'][0]['act']['code']);
        $this->assertSame('art3', $result['rules'][0]['evidence'][0]['segment']);
        $this->assertSame(['br', 'mg'], array_column($result['catalogs'], 'jurisdiction'));
        $this->assertNotEmpty($result['catalogs'][1]['checksum_sha256']);
    }

    public function test_non_natives_in_the_doce_piracema_combine_state_conditions(): void
    {
        $result = $this->evaluate('doce', 'tilápia', '2026-12-15');

        $this->assertSame('conditional', $result['status']);
        $this->assertSame(['doce-nao-nativas', 'leste-defeso-nao-nativas'], array_column($result['rules'], 'code'));
        $conditions = array_column($result['conditions'], 'text');
        $this->assertContains('limite de 3 kg + 1 exemplar por jornada (amador e profissional)', $conditions);
        $this->assertContains('amador: 10 kg + 1 exemplar de qualquer tamanho acima do mínimo legal', $conditions);
        $this->assertSame(['mg'], array_values(array_unique(array_column($result['conditions'], 'jurisdiction'))));
        $this->assertContains('leste-defeso-faixas', array_column($result['location_restrictions'], 'code'));
        $this->assertNotEmpty(array_filter($result['gaps'], fn ($gap) => str_contains($gap, 'restrições por local')));
    }

    public function test_sao_francisco_piracema_conditions_non_natives_and_covers_leap_day(): void
    {
        $pacu = $this->evaluate('sao_francisco', 'pacu', '2026-12-01');
        $this->assertSame('conditional', $pacu['status']);
        $this->assertContains('pesca profissional só para consumo familiar, sem comercialização', array_column($pacu['conditions'], 'text'));

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

    public function test_threatened_species_are_prohibited_all_year_in_any_basin(): void
    {
        $surubim = $this->evaluate('sao_francisco', 'Pseudoplatystoma corruscans', '2027-06-15');
        $this->assertSame('prohibited', $surubim['status']);
        $this->assertSame(['br-ameacadas-protecao-integral'], array_column($surubim['rules'], 'code'));
        $this->assertSame('VU', $surubim['species']['threatened'][0]['category']);
        $this->assertTrue($surubim['rules'][0]['verified']);

        $doce = $this->evaluate('doce', 'Prochilodus vimboides', '2027-06-15');
        $this->assertSame(['br-ameacadas-protecao-integral', 'doce-nativas-permanente'], array_column($doce['rules'], 'code'));
    }

    public function test_a_threatened_non_native_is_prohibited_even_when_the_state_allows_it(): void
    {
        // Tambaqui is listed as capturable by Portaria IEF 154/2011 and as VU nationally.
        $result = $this->evaluate('sao_francisco', 'tambaqui', '2026-12-15');

        $this->assertSame('prohibited', $result['status']);
        $this->assertSame('non_native', $result['species']['classification']);
        $this->assertSame('Colossoma macropomum', $result['species']['threatened'][0]['name']);
        $this->assertSame(['br-ameacadas-protecao-integral'], array_column($result['rules'], 'code'));
        $this->assertSame([], $result['conditions']);
    }

    public function test_threatened_list_waits_for_its_transition_date(): void
    {
        $result = $this->evaluate('sao_francisco', 'Pseudoplatystoma corruscans', '2026-09-01');

        $this->assertSame('undetermined', $result['status']);
        $this->assertNotEmpty(array_filter($result['gaps'], fn ($gap) => str_contains($gap, '2026-10-27')));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->postJson('/api/fishing/evaluate', ['latitude' => 95, 'longitude' => 0, 'species' => 'x', 'date' => '15/12/2026'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['latitude', 'species', 'date']);
    }

    public function test_federal_and_state_rules_combine_and_name_their_jurisdiction(): void
    {
        $result = $this->evaluate('doce', 'Pimelodus maculatus', '2026-12-15');

        $this->assertSame('prohibited', $result['status']);
        $this->assertSame(['mg'], $result['location']['states']);
        $this->assertSame(['br', 'mg'], $result['location']['jurisdictions']);
        $this->assertSame('undetermined', $result['location']['water_domain']);
        $jurisdictions = array_column($result['rules'], 'jurisdiction', 'code');
        $this->assertSame('br', $jurisdictions['leste-defeso-federal']);
        $this->assertSame('mg', $jurisdictions['leste-defeso-nativas']);
        $this->assertSame('mg', $jurisdictions['doce-nativas-permanente']);
    }

    public function test_federal_basin_restrictions_reach_the_state_area(): void
    {
        $result = $this->evaluate('sao_francisco', 'tucunaré', '2027-04-10');

        $lagoons = array_column($result['location_restrictions'], null, 'code')['sf-lagoas-marginais-federal'];
        $this->assertSame('br', $lagoons['jurisdiction']);
        $this->assertContains('br:sao_francisco', array_column($result['location']['areas'], 'code'));
    }

    public function test_without_the_federal_catalog_the_api_refuses_to_answer(): void
    {
        DB::table('legal_catalog_versions')->where('jurisdiction', 'br')->update(['published_at' => null]);

        $this->postJson('/api/fishing/evaluate', ['latitude' => 10.5, 'longitude' => 10.5, 'species' => 'tambaqui'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'catalog_unavailable');
    }

    public function test_a_point_in_a_state_without_a_published_catalog_is_undetermined(): void
    {
        DB::table('legal_catalog_versions')->where('jurisdiction', 'mg')->update(['published_at' => null]);

        $result = $this->evaluate('doce', 'Pimelodus maculatus', '2027-06-15');

        $this->assertSame('undetermined', $result['status']);
        $this->assertSame([], $result['rules']);
        $this->assertSame(['br'], array_column($result['catalogs'], 'jurisdiction'));
        $this->assertNotEmpty(array_filter($result['gaps'], fn ($gap) => str_contains($gap, 'catálogo de mg não está publicado')));
    }

    public function test_without_a_published_catalog_the_api_refuses_to_answer(): void
    {
        DB::table('legal_catalog_versions')->update(['published_at' => null]);

        $this->postJson('/api/fishing/evaluate', ['latitude' => 10.5, 'longitude' => 10.5, 'species' => 'tilápia'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'catalog_unavailable');
    }
}
