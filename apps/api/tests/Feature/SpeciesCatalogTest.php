<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SyntheticLegalAreas;
use Tests\TestCase;

/**
 * Species search (GET /api/species) and the per-area species list
 * (GET /api/fishing/species), against the repository catalogs.
 */
class SpeciesCatalogTest extends TestCase
{
    use DatabaseTransactions;
    use SyntheticLegalAreas;

    private const TAMBACU = 'colossoma-macropomum-x-piaractus-masopotamicus';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSyntheticLegalAreas();
    }

    private function areaSpecies(string $area, string $date, ?string $status = null): array
    {
        $query = http_build_query($this->pointIn($area) + ['date' => $date] + ($status ? ['status' => $status] : []));

        return $this->getJson("/api/fishing/species?{$query}")->assertOk()->json();
    }

    public function test_search_finds_a_species_by_common_name_and_groups_misspellings(): void
    {
        $result = $this->getJson('/api/species?q=tucunaré')->assertOk()->json();

        $tucunare = $result['items'][0];
        $this->assertSame('cichla-spp', $tucunare['id']);
        $this->assertSame('Cichla spp.', $tucunare['scientific_name']);
        $this->assertSame('genus', $tucunare['rank']);
        $this->assertSame(['Cicla spp.'], $tucunare['written_as']);
        $entries = array_column($tucunare['lists'], 'entry', 'list');
        $this->assertSame('Tucunaré (Cicla spp.)', $entries['ief-154-2011-art5']);
        $this->assertSame('Tucunaré (Cichla spp.)', $entries['ief-155-2011-art7']);
        $this->assertSame(['br', 'mg'], array_column($result['catalogs'], 'jurisdiction'));
    }

    public function test_search_finds_threatened_species_by_scientific_name_and_genus_entries(): void
    {
        $surubim = $this->getJson('/api/species?q=Pseudoplatystoma corruscans')->assertOk()->json('items.0');
        $this->assertSame('pseudoplatystoma-corruscans', $surubim['id']);
        $this->assertSame('VU', $surubim['threatened'][0]['category']);
        $this->assertSame('Pimelodidae', $surubim['family']);

        $kelberi = $this->getJson('/api/species?q=Cichla kelberi')->assertOk()->json('items');
        $this->assertSame(['cichla-spp'], array_column($kelberi, 'id'));

        $this->getJson('/api/species?q=x')->assertStatus(422)->assertJsonValidationErrors(['q']);
        $this->getJson('/api/species/nao-existe')->assertNotFound();
        $this->getJson('/api/species/'.self::TAMBACU)->assertOk()->assertJsonPath('name', 'Tambacu');
    }

    public function test_a_corrected_spelling_reaches_the_evaluation(): void
    {
        // Portaria IEF 154/2011 writes "Cicla spp."; the valid name now matches it.
        $result = $this->postJson('/api/fishing/evaluate', $this->pointIn('sao_francisco') + [
            'species' => 'Cichla kelberi', 'date' => '2026-12-01',
        ])->assertOk()->json();

        $this->assertSame('conditional', $result['status']);
        $this->assertSame('non_native', $result['species']['classification']);
        $this->assertSame(['sf-defeso-nao-nativas'], array_column($result['rules'], 'code'));
    }

    public function test_evaluation_accepts_a_species_id_from_the_search(): void
    {
        // "Tambacu" alone in Portaria IEF 155/2011 joins the cross named in 154/2011.
        $result = $this->postJson('/api/fishing/evaluate', $this->pointIn('doce') + [
            'species_id' => self::TAMBACU, 'date' => '2026-12-15',
        ])->assertOk()->json();

        $this->assertSame('conditional', $result['status']);
        $this->assertSame(self::TAMBACU, $result['species']['id']);
        $this->assertSame('Tambacu', $result['species']['name']);
        $this->assertSame(['doce-nao-nativas', 'leste-defeso-nao-nativas'], array_column($result['rules'], 'code'));

        $this->postJson('/api/fishing/evaluate', $this->pointIn('doce') + ['species_id' => 'nao-existe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['species_id']);
        $this->postJson('/api/fishing/evaluate', $this->pointIn('doce'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['species', 'species_id']);
    }

    public function test_area_list_gives_each_cited_species_its_status(): void
    {
        $result = $this->areaSpecies('doce', '2027-06-15');

        $this->assertSame(['mg:doce', 'mg:leste', 'mg', 'br:leste', 'br'], array_column($result['location']['areas'], 'code'));
        $this->assertSame(['status' => 'prohibited', 'rules' => ['doce-nativas-permanente']], $result['defaults']['native']);
        $this->assertSame(['status' => 'conditional', 'rules' => ['doce-nao-nativas']], $result['defaults']['non_native']);

        $species = array_column($result['species'], null, 'id');
        $this->assertSame('prohibited', $species['pimelodus-maculatus']['status']);
        $this->assertSame('native', $species['pimelodus-maculatus']['classification']);
        $this->assertSame(['br-ameacadas-protecao-integral', 'doce-nativas-permanente'], $species['prochilodus-vimboides']['rules']);
        $this->assertSame('VU', $species['prochilodus-vimboides']['threatened']['category']);
        $this->assertSame('prohibited', $species['colossoma-macropomum']['status']);
        $this->assertSame('conditional', $species['oreochromis-spp']['status']);
        $this->assertSame(['doce-nao-nativas'], $species['oreochromis-spp']['rules']);
        $this->assertSame('conditional', $species[self::TAMBACU]['status']);

        // Most restricted first, and the summary counts every listed species.
        $statuses = array_column($result['species'], 'status');
        $this->assertSame('prohibited', $statuses[0]);
        $this->assertSame('conditional', end($statuses));
        $this->assertSame(count($result['species']), array_sum($result['summary']));
        $this->assertGreaterThan(350, $result['summary']['prohibited']);

        $rules = array_column($result['rules'], null, 'code');
        $this->assertContains('licença de pesca', $rules['doce-nao-nativas']['conditions']);
        $this->assertSame('br', $rules['br-ameacadas-protecao-integral']['jurisdiction']);
    }

    public function test_area_list_filters_by_status(): void
    {
        $result = $this->areaSpecies('grande', '2027-01-10', 'conditional,undetermined');

        $this->assertNotEmpty($result['species']);
        $this->assertSame([], array_diff(array_column($result['species'], 'status'), ['conditional', 'undetermined']));
        $this->assertGreaterThan(0, $result['summary']['prohibited']);
        $piaucu = array_column($result['species'], null, 'id')['leporinus-macrocephalus'] ?? null;
        $this->assertNull($piaucu, 'piauçu is prohibited in the Grande piracema');

        $this->getJson('/api/fishing/species?'.http_build_query($this->pointIn('grande') + ['status' => 'allowed']))
            ->assertStatus(422);
    }

    public function test_area_list_matches_the_single_species_evaluation(): void
    {
        foreach (['doce' => '2026-12-15', 'sao_francisco' => '2026-12-01', 'grande' => '2027-06-15'] as $area => $date) {
            $listed = $this->areaSpecies($area, $date, 'conditional,undetermined')['species'];
            $this->assertNotEmpty($listed, $area);
            foreach ($listed as $row) {
                $single = $this->postJson('/api/fishing/evaluate', $this->pointIn($area) + ['species_id' => $row['id'], 'date' => $date])
                    ->assertOk()->json();
                $this->assertSame($row['status'], $single['status'], "{$area} {$row['id']}");
                $this->assertSame($row['rules'], array_column($single['rules'], 'code'), "{$area} {$row['id']}");
            }
        }
    }

    public function test_area_list_outside_mapped_areas_and_without_catalog(): void
    {
        $outside = $this->getJson('/api/fishing/species?latitude=-80&longitude=-170&date=2026-12-15')->assertOk()->json();
        $this->assertSame([], $outside['species']);
        $this->assertNull($outside['defaults']);
        $this->assertNotEmpty(array_filter($outside['gaps'], fn ($gap) => str_contains($gap, 'nenhuma área legal mapeada')));

        DB::table('legal_catalog_versions')->update(['published_at' => null]);
        $this->getJson('/api/fishing/species?latitude=10.5&longitude=10.5')->assertStatus(503);
        $this->getJson('/api/species?q=tilapia')->assertStatus(503);
    }
}
