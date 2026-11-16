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
 *
 * Rules come from every jurisdiction whose areas contain the point: the
 * federal catalog ("br") plus each state catalog ("mg", ...). The most
 * restrictive outcome wins and every rule and condition names its
 * jurisdiction (docs/adr/0001-jurisdicoes.md).
 */
final class FishingEvaluator
{
    public const PROHIBITED = 'prohibited';

    public const CONDITIONAL = 'conditional';

    public const UNDETERMINED = 'undetermined';

    /** Domain of the water body (federal or state); not mapped yet. */
    public const WATER_DOMAIN_UNDETERMINED = 'undetermined';

    public function __construct(
        private readonly LegalAreaResolver $areas,
        private readonly SpeciesClassifier $species,
        private readonly SpeciesIndex $index,
    ) {}

    /**
     * Status of one species at a point on a date. The species is given by name
     * (common or scientific) or by the id of a SpeciesIndex taxon.
     *
     * @throws CatalogUnavailable without the federal catalog
     * @throws UnknownSpecies when $speciesId is not a taxon of the published catalogs
     */
    public function evaluate(float $latitude, float $longitude, ?string $speciesName, CarbonImmutable $date, ?string $speciesId = null): array
    {
        $context = $this->context($latitude, $longitude, $date);
        $taxon = null;
        if ($speciesId !== null) {
            $taxon = $this->index->find($speciesId, $context['published_ids']) ?? throw new UnknownSpecies($speciesId);
        }

        $result = $context['result'];
        $result['species'] = array_filter([
            'input' => $speciesName ?? $speciesId,
            'id' => $taxon['id'] ?? null,
            'name' => $taxon['name'] ?? null,
            'scientific_name' => $taxon['scientific_name'] ?? null,
        ], fn ($value) => $value !== null) + ['classification' => null, 'matches' => [], 'threatened' => []];
        $result += ['rules' => [], 'conditions' => [], 'location_restrictions' => []];
        $result['gaps'] = $context['gaps'];
        if (! $context['ready']) {
            return self::ordered($result);
        }

        $species = $taxon !== null
            ? self::classifyTaxon(SpeciesIndex::restrict($taxon, $context['lineage']))
            : $this->species->classify($speciesName, $context['lineage'], $context['version_ids']);
        $result['species']['classification'] = $species['classification'];
        $result['species']['matches'] = $species['matches'];
        $result['species']['threatened'] = $species['threatened'];

        $decision = self::decideSpecies($context, $species, $date);
        $result['status'] = $decision['status'];
        $result['rules'] = array_map(fn (array $rule) => self::present($rule), $decision['applied']);
        if ($decision['status'] === self::CONDITIONAL) {
            $result['conditions'] = self::conditionsOf($decision['applied']);
        }
        $result['location_restrictions'] = array_map(fn (array $rule) => self::present($rule) + ['geo_mapping' => $rule['geo_mapping']], $context['local']);
        array_push($result['gaps'], ...$decision['gaps']);
        if ($context['local'] !== [] && $decision['status'] !== self::PROHIBITED) {
            $result['gaps'][] = self::LOCAL_GAP;
        }
        foreach ($decision['applied'] as $rule) {
            if (! $rule['verified']) {
                $result['gaps'][] = self::unverifiedGap($rule);
            }
        }

        return self::ordered($result);
    }

    /**
     * Every species cited by the official lists that apply at a point, each with
     * its status on the date, most restricted first. Species outside the lists
     * are not enumerable: the "defaults" block says what applies to an unlisted
     * native or non-native species.
     *
     * @param  list<string>|null  $statuses  keep only these statuses
     *
     * @throws CatalogUnavailable without the federal catalog
     */
    public function speciesAt(float $latitude, float $longitude, CarbonImmutable $date, ?array $statuses = null): array
    {
        $context = $this->context($latitude, $longitude, $date);
        $result = $context['result'];
        unset($result['status']);
        $result += ['summary' => [self::PROHIBITED => 0, self::CONDITIONAL => 0, self::UNDETERMINED => 0], 'defaults' => null, 'species' => [], 'rules' => [], 'location_restrictions' => []];
        $result['gaps'] = $context['gaps'];
        if (! $context['ready']) {
            return $result;
        }

        $rules = [];
        $used = function (array $applied) use (&$rules): array {
            foreach ($applied as $rule) {
                $rules[$rule['code']] ??= $rule;
            }

            return array_column($applied, 'code');
        };

        foreach ([SpeciesClassifier::NATIVE, SpeciesClassifier::NON_NATIVE] as $classification) {
            [$status, $applied] = self::decide($context['basin'], $classification);
            $result['defaults'][$classification] = ['status' => $status, 'rules' => $used($applied)];
        }

        $rows = [];
        foreach ($this->index->forLineage($context['published_ids'], $context['lineage']) as $taxon) {
            $species = self::classifyTaxon($taxon);
            $decision = self::decideSpecies($context, $species, $date);
            $result['summary'][$decision['status']]++;
            if ($statuses !== null && ! in_array($decision['status'], $statuses, true)) {
                continue;
            }
            $threatened = $species['threatened'][0] ?? null;
            $rows[] = array_filter([
                'id' => $taxon['id'],
                'name' => $taxon['name'],
                'scientific_name' => $taxon['scientific_name'],
                'rank' => $taxon['rank'],
                'common_names' => $taxon['common_names'],
                'family' => $taxon['family'],
                'classification' => $species['classification'],
                'threatened' => $threatened === null ? null : ['category' => $threatened['category'], 'list' => $threatened['list'], 'act' => $threatened['act']],
                'status' => $decision['status'],
                'rules' => $used($decision['applied']),
                // A prohibition settles the answer; the classification gaps behind it add nothing.
                'gaps' => $decision['status'] === self::PROHIBITED ? [] : $decision['gaps'],
            ], fn ($value) => $value !== null);
        }
        $rank = [self::PROHIBITED => 0, self::CONDITIONAL => 1, self::UNDETERMINED => 2];
        usort($rows, fn ($a, $b) => [$rank[$a['status']], SpeciesClassifier::normalize($a['name'])] <=> [$rank[$b['status']], SpeciesClassifier::normalize($b['name'])]);
        $result['species'] = $rows;

        ksort($rules);
        $result['rules'] = array_values(array_map(fn (array $rule) => self::present($rule) + ['conditions' => $rule['conditions']], $rules));
        $result['location_restrictions'] = array_map(fn (array $rule) => self::present($rule) + ['geo_mapping' => $rule['geo_mapping']], $context['local']);
        if ($context['local'] !== []) {
            $result['gaps'][] = self::LOCAL_GAP;
        }
        foreach ($rules as $rule) {
            if (! $rule['verified']) {
                $result['gaps'][] = self::unverifiedGap($rule);
            }
        }

        return $result;
    }

    private const LOCAL_GAP = 'Há restrições por local (faixas, lagoas, trechos ou UCs) ainda não verificadas geograficamente; confira location_restrictions.';

    private static function unverifiedGap(array $rule): string
    {
        return "A regra {$rule['code']} não tem trecho literal conferido no texto oficial armazenado.";
    }

    /**
     * Location, catalogs and active rules at a point on a date; shared by the
     * single-species evaluation and the per-area species list.
     */
    private function context(float $latitude, float $longitude, CarbonImmutable $date): array
    {
        $published = CatalogImporter::publishedVersions();
        // Without the federal catalog a threatened species could come out as
        // "conditional", so the API refuses to answer rather than under-restrict.
        if (! isset($published[LegalAreaResolver::FEDERAL])) {
            throw new CatalogUnavailable('No published federal legal catalog ('.LegalAreaResolver::FEDERAL.')');
        }
        $publishedIds = array_map(fn ($version) => (int) $version->id, array_values($published));
        $location = $this->areas->at($latitude, $longitude, $publishedIds);
        $used = array_intersect_key($published, array_flip($location['jurisdictions'] ?: [LegalAreaResolver::FEDERAL]));
        $versionIds = array_map(fn ($version) => (int) $version->id, array_values($used));

        $result = [
            'status' => self::UNDETERMINED,
            'date' => $date->toDateString(),
            'location' => [
                'latitude' => $latitude,
                'longitude' => $longitude,
                'geographic_match' => $location['geographic_match'],
                'matched' => $location['matched'],
                'states' => $location['states'],
                'jurisdictions' => $location['jurisdictions'],
                'water_domain' => self::WATER_DOMAIN_UNDETERMINED,
                'lineage' => $location['lineage'],
                'areas' => $location['areas'],
            ],
            'catalogs' => array_values(array_map(fn ($version) => [
                'jurisdiction' => $version->jurisdiction,
                'version_id' => (int) $version->id,
                'checksum_sha256' => $version->checksum_sha256,
                'published_at' => CarbonImmutable::parse($version->published_at)->toIso8601String(),
            ], $used)),
            'disclaimer' => 'Resposta informativa baseada nos catálogos publicados; não substitui a consulta à norma oficial nem a orientação do órgão ambiental.',
        ];
        $context = [
            'result' => $result,
            'published_ids' => $publishedIds,
            'version_ids' => $versionIds,
            'lineage' => $location['lineage'],
            'gaps' => [],
            'ready' => false,
            'basin' => [],
            'local' => [],
        ];

        if (! $location['geographic_match']) {
            $context['gaps'][] = 'O ponto não está em nenhuma área legal mapeada; o mapeamento geográfico das bacias pode estar incompleto.';
            unset($context['result']['location']['matched']);

            return $context;
        }
        foreach ($location['unpublished'] as $code) {
            $jurisdiction = LegalAreaResolver::jurisdictionOf($code);
            $context['gaps'][] = "O ponto está na área {$code}, mas o catálogo de {$jurisdiction} não está publicado; as normas dessa jurisdição não foram avaliadas.";
        }
        if ($location['lineage'] === []) {
            return $context;
        }

        $active = array_values(array_filter(
            $this->rulesFor($versionIds, $location['lineage']),
            fn (array $rule) => self::inPeriod($rule, $date),
        ));
        $context['basin'] = array_values(array_filter($active, fn (array $rule) => $rule['scope'] === 'basin'));
        $context['local'] = array_values(array_filter($active, fn (array $rule) => $rule['scope'] !== 'basin'));
        $context['ready'] = true;

        return $context;
    }

    /** Classification of a SpeciesIndex taxon from the citations it kept. */
    private static function classifyTaxon(array $taxon): array
    {
        $strip = fn (array $citation, array $keys) => array_intersect_key($citation, array_flip($keys));

        return [
            'classification' => SpeciesClassifier::classificationOf($taxon['matches']),
            'matches' => array_map(fn ($m) => $strip($m, ['list', 'act', 'entry', 'classification']), $taxon['matches']),
            'threatened' => array_map(fn ($t) => $strip($t, ['list', 'act', 'name', 'category', 'valid_from']), $taxon['threatened']),
        ];
    }

    /**
     * Status of a classified species under the active basin rules.
     *
     * @return array{status: string, applied: list<array>, gaps: list<string>}
     */
    private static function decideSpecies(array $context, array $species, CarbonImmutable $date): array
    {
        $gaps = [];
        $basin = $context['basin'];
        [$status, $applied] = match ($species['classification']) {
            SpeciesClassifier::NATIVE, SpeciesClassifier::NON_NATIVE => self::decide($basin, $species['classification']),
            SpeciesClassifier::EXCLUDED => self::decideExcluded($basin, $species['matches']),
            default => self::decideUnclassified($basin, $species['classification'], $gaps),
        };

        // A species on an official list (e.g. threatened species) is prohibited by
        // that list's rule whatever its native status.
        $listed = self::listedProhibitions($basin, $species['threatened'], $date, $gaps);
        if ($listed !== []) {
            $status = self::PROHIBITED;
            $applied = array_values(array_merge($listed, array_filter($applied, fn ($rule) => $rule['effect'] === self::PROHIBITED)));
        }

        if ($status === self::UNDETERMINED && $applied === [] && $species['classification'] !== SpeciesClassifier::UNKNOWN
            && $species['classification'] !== SpeciesClassifier::CONFLICTING) {
            $gaps[] = 'Nenhuma regra carregada para esta espécie, área e data (ex.: tamanhos mínimos e cotas fora do defeso ainda não catalogados).';
        }

        return ['status' => $status, 'applied' => $applied, 'gaps' => $gaps];
    }

    /** Keeps the response keys in the documented order. */
    private static function ordered(array $result): array
    {
        $order = ['status', 'date', 'location', 'species', 'rules', 'conditions', 'location_restrictions', 'gaps', 'catalogs', 'disclaimer'];

        return array_merge(array_intersect_key(array_flip($order), $result), $result);
    }

    /**
     * Conditions of every applied rule, each naming the rule and jurisdiction it
     * comes from; the same text from the same jurisdiction appears once.
     *
     * @return list<array{text: string, rule: string, jurisdiction: string}>
     */
    private static function conditionsOf(array $rules): array
    {
        $conditions = [];
        foreach ($rules as $rule) {
            foreach ($rule['conditions'] as $text) {
                $conditions[$rule['jurisdiction']."\0".$text] ??= ['text' => $text, 'rule' => $rule['code'], 'jurisdiction' => $rule['jurisdiction']];
            }
        }

        return array_values($conditions);
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

    private function rulesFor(array $versionIds, array $areaCodes): array
    {
        $rows = DB::table('legal_rules')
            ->join('legal_acts', 'legal_acts.id', '=', 'legal_rules.legal_act_id')
            ->join('legal_catalog_versions', 'legal_catalog_versions.id', '=', 'legal_rules.catalog_version_id')
            ->whereIn('legal_rules.catalog_version_id', $versionIds)
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
                'legal_catalog_versions.jurisdiction as jurisdiction',
            ]);

        $evidence = DB::table('legal_rule_evidence')
            ->whereIn('legal_rule_id', $rows->pluck('id'))
            ->orderBy('legal_rule_id')->orderBy('position')
            ->get()
            ->groupBy('legal_rule_id');

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'code' => $row->code,
            'jurisdiction' => $row->jurisdiction,
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
            'jurisdiction' => $rule['jurisdiction'],
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
