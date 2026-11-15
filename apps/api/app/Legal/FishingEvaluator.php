<?php

namespace App\Legal;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Decides whether fishing a species at a point on a date is prohibited or
 * conditional, using only rules of the published legal catalog.
 *
 * The evaluator never answers "allowed": the catalog only holds restrictions,
 * so the absence of a restriction is reported as "undetermined" with the gap
 * that prevents a firmer answer. Same semantics as services/legal-catalog.
 */
final class FishingEvaluator
{
    public const PROHIBITED = 'prohibited';

    public const CONDITIONAL = 'conditional';

    public const UNDETERMINED = 'undetermined';

    public const JURISDICTION = 'mg';

    public function __construct(
        private readonly LegalAreaResolver $areas,
        private readonly SpeciesClassifier $species,
    ) {}

    public function evaluate(float $latitude, float $longitude, string $speciesName, CarbonImmutable $date): array
    {
        $versionId = CatalogImporter::currentVersionId(self::JURISDICTION);
        if ($versionId === null) {
            throw new CatalogUnavailable('No published legal catalog for '.self::JURISDICTION);
        }
        $version = DB::table('legal_catalog_versions')->find($versionId);
        $location = $this->areas->at($latitude, $longitude, $versionId);
        $result = [
            'status' => self::UNDETERMINED,
            'date' => $date->toDateString(),
            'location' => ['latitude' => $latitude, 'longitude' => $longitude] + $location,
            'species' => ['input' => $speciesName, 'classification' => null, 'matches' => []],
            'rules' => [],
            'conditions' => [],
            'location_restrictions' => [],
            'gaps' => [],
            'catalog' => [
                'version_id' => (int) $version->id,
                'checksum_sha256' => $version->checksum_sha256,
                'published_at' => CarbonImmutable::parse($version->published_at)->toIso8601String(),
            ],
            'disclaimer' => 'Resposta informativa baseada no catálogo publicado; não substitui a consulta à norma oficial nem a orientação do órgão ambiental.',
        ];

        if (! $location['geographic_match']) {
            $result['gaps'][] = 'O ponto não está em nenhuma área legal mapeada; o mapeamento geográfico das bacias pode estar incompleto.';
            unset($result['location']['matched']);

            return $result;
        }

        $species = $this->species->classify($speciesName, $location['lineage'], $versionId);
        $result['species']['classification'] = $species['classification'];
        $result['species']['matches'] = $species['matches'];
        $result['species']['threatened'] = $species['threatened'];

        $active = array_values(array_filter(
            $this->rulesFor($versionId, $location['lineage']),
            fn (array $rule) => self::inPeriod($rule, $date),
        ));
        $basin = array_values(array_filter($active, fn (array $rule) => $rule['scope'] === 'basin'));
        $local = array_values(array_filter($active, fn (array $rule) => $rule['scope'] !== 'basin'));

        [$status, $applied] = match ($species['classification']) {
            SpeciesClassifier::NATIVE, SpeciesClassifier::NON_NATIVE => self::decide($basin, $species['classification']),
            SpeciesClassifier::EXCLUDED => self::decideExcluded($basin, $species['matches']),
            default => self::decideUnclassified($basin, $species['classification'], $result['gaps']),
        };

        // A species on an official list (e.g. threatened species) is prohibited by
        // that list's rule whatever its native status.
        $listed = self::listedProhibitions($basin, $species['threatened'], $date, $result['gaps']);
        if ($listed !== []) {
            $status = self::PROHIBITED;
            $applied = array_values(array_merge($listed, array_filter($applied, fn ($rule) => $rule['effect'] === self::PROHIBITED)));
        }

        $result['status'] = $status;
        $result['rules'] = array_map(fn (array $rule) => self::present($rule), $applied);
        if ($status === self::CONDITIONAL) {
            $result['conditions'] = array_values(array_unique(array_merge(...array_map(fn ($rule) => $rule['conditions'], $applied))));
        }
        $result['location_restrictions'] = array_map(fn (array $rule) => self::present($rule) + ['geo_mapping' => $rule['geo_mapping']], $local);

        if ($status === self::UNDETERMINED && $applied === [] && $species['classification'] !== SpeciesClassifier::UNKNOWN
            && $species['classification'] !== SpeciesClassifier::CONFLICTING) {
            $result['gaps'][] = 'Nenhuma regra carregada para esta espécie, área e data (ex.: tamanhos mínimos e cotas fora do defeso ainda não catalogados).';
        }
        if ($local !== [] && $status !== self::PROHIBITED) {
            $result['gaps'][] = 'Há restrições por local (faixas, lagoas, trechos ou UCs) ainda não verificadas geograficamente; confira location_restrictions.';
        }
        foreach ($applied as $rule) {
            if (! $rule['verified']) {
                $result['gaps'][] = "A regra {$rule['code']} não tem trecho literal conferido no texto oficial armazenado.";
            }
        }

        return $result;
    }

    private static function listedProhibitions(array $basinRules, array $threatened, CarbonImmutable $date, array &$gaps): array
    {
        $rules = [];
        foreach ($threatened as $species) {
            foreach ($basinRules as $rule) {
                if ($rule['species_scope'] !== 'threatened' || $rule['species_list'] !== $species['list'] || $rule['effect'] !== self::PROHIBITED) {
                    continue;
                }
                if ($species['valid_from'] !== null && $date->toDateString() < $species['valid_from']) {
                    $gaps[] = "{$species['name']} ({$species['category']}) consta da lista {$species['list']}, mas a data é anterior a {$species['valid_from']}, quando as proibições passam a valer para espécies novas na lista.";

                    continue;
                }
                $rules[$rule['code']] = $rule;
            }
        }

        return array_values($rules);
    }

    /** @return array{string, list<array>} */
    private static function decide(array $basinRules, string $classification): array
    {
        $applicable = array_values(array_filter($basinRules, fn ($rule) => in_array($rule['species_scope'], [$classification, 'all'], true)));
        $prohibited = array_values(array_filter($applicable, fn ($rule) => $rule['effect'] === self::PROHIBITED));
        if ($prohibited !== []) {
            return [self::PROHIBITED, $prohibited];
        }
        $conditional = array_values(array_filter($applicable, fn ($rule) => $rule['effect'] === self::CONDITIONAL));
        if ($conditional !== []) {
            return [self::CONDITIONAL, $conditional];
        }

        return [self::UNDETERMINED, []];
    }

    /**
     * A species expressly excluded from a permission (e.g. piauçu in Portaria IEF
     * 156/2011) is prohibited while that permission is the only way to fish it.
     */
    private static function decideExcluded(array $basinRules, array $matches): array
    {
        $acts = array_column(array_filter($matches, fn ($m) => $m['classification'] === SpeciesClassifier::EXCLUDED), 'act');
        $permissions = array_values(array_filter($basinRules, fn ($rule) => $rule['effect'] === self::CONDITIONAL
            && $rule['species_scope'] === 'non_native' && in_array($rule['act']['code'], $acts, true)));

        return $permissions === [] ? [self::UNDETERMINED, []] : [self::PROHIBITED, $permissions];
    }

    /**
     * Unknown or conflicting species: answer only when every classification
     * leads to the same status.
     */
    private static function decideUnclassified(array $basinRules, string $classification, array &$gaps): array
    {
        $gaps[] = $classification === SpeciesClassifier::CONFLICTING
            ? 'A espécie aparece com classificações diferentes nas listas oficiais aplicáveis.'
            : 'A espécie não consta das listas oficiais aplicáveis; não é possível saber se é nativa nesta bacia.';
        [$asNative, $nativeRules] = self::decide($basinRules, SpeciesClassifier::NATIVE);
        [$asOther, $otherRules] = self::decide($basinRules, SpeciesClassifier::NON_NATIVE);
        if ($asNative === $asOther && $asNative === self::PROHIBITED) {
            return [self::PROHIBITED, array_values(array_unique(array_merge($nativeRules, $otherRules), SORT_REGULAR))];
        }

        return [self::UNDETERMINED, []];
    }

    public static function inPeriod(array $rule, CarbonImmutable $date): bool
    {
        if ($rule['period_kind'] === 'permanent') {
            return true;
        }
        $day = $date->format('m-d');
        $start = $rule['period_start'];
        // Conservative choice, pending approval: a period ending on 28 February
        // also covers 29 February in leap years (same as services/legal-catalog).
        $end = $rule['period_end'] === '02-28' ? '02-29' : $rule['period_end'];

        return $start <= $end ? ($start <= $day && $day <= $end) : ($day >= $start || $day <= $end);
    }

    private function rulesFor(int $versionId, array $areaCodes): array
    {
        $rows = DB::table('legal_rules')
            ->join('legal_acts', 'legal_acts.id', '=', 'legal_rules.legal_act_id')
            ->where('legal_rules.catalog_version_id', $versionId)
            ->whereExists(fn ($query) => $query->select(DB::raw(1))
                ->from('legal_rule_areas')
                ->join('legal_areas', 'legal_areas.id', '=', 'legal_rule_areas.legal_area_id')
                ->whereColumn('legal_rule_areas.legal_rule_id', 'legal_rules.id')
                ->whereIn('legal_areas.code', $areaCodes))
            ->orderBy('legal_rules.code')
            ->get([
                'legal_rules.*',
                'legal_acts.code as act_code', 'legal_acts.title as act_title',
                'legal_acts.official_url as act_official_url', 'legal_acts.read_from as act_read_from',
                'legal_acts.kind as act_kind',
            ]);

        $evidence = DB::table('legal_rule_evidence')
            ->whereIn('legal_rule_id', $rows->pluck('id'))
            ->orderBy('legal_rule_id')->orderBy('position')
            ->get()
            ->groupBy('legal_rule_id');

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'code' => $row->code,
            'act' => [
                'code' => $row->act_code,
                'title' => $row->act_title,
                'kind' => $row->act_kind,
                'url' => $row->act_official_url ?? $row->act_read_from,
            ],
            'articles' => $row->articles,
            'period_kind' => $row->period_kind,
            'period_start' => $row->period_start,
            'period_end' => $row->period_end,
            'species_scope' => $row->species_scope,
            'species_list' => $row->species_list,
            'scope' => $row->scope,
            'effect' => $row->effect,
            'summary' => $row->summary,
            'conditions' => json_decode($row->conditions, true),
            'stretches' => json_decode($row->stretches, true),
            'geo_mapping' => $row->geo_mapping,
            'verified' => (bool) $row->verified,
            'evidence' => ($evidence[$row->id] ?? collect())->map(fn ($e) => ['segment' => $e->segment, 'quote' => $e->quote])->values()->all(),
        ])->all();
    }

    private static function present(array $rule): array
    {
        $period = $rule['period_kind'] === 'permanent'
            ? ['kind' => 'permanent']
            : ['kind' => 'annual', 'start' => $rule['period_start'], 'end' => $rule['period_end']];

        return array_filter([
            'code' => $rule['code'],
            'effect' => $rule['effect'],
            'summary' => $rule['summary'],
            'act' => $rule['act'],
            'articles' => $rule['articles'],
            'period' => $period,
            'species_scope' => $rule['species_scope'],
            'stretches' => $rule['stretches'] ?: null,
            'verified' => $rule['verified'],
            'evidence' => $rule['evidence'],
        ], fn ($value) => $value !== null);
    }
}
