<?php

namespace App\Legal;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Imports the canonical legal catalog produced by services/legal-catalog/catalog.py.
 *
 * The file's SHA-256 identifies the version: importing the same file again is a
 * no-op, and a changed file always becomes a new version. Nothing is written
 * unless the whole catalog is consistent.
 */
class CatalogImporter
{
    public const FORMAT = 'fishing-regulation-legal-catalog/2';

    /**
     * @return array{version_id: int, jurisdiction: string, created: bool, published: bool, checksum_sha256: string}
     */
    public function import(string $path, bool $publish = false): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Catalog file not found: {$path}");
        }
        $contents = file_get_contents($path);
        $checksum = hash('sha256', $contents);
        $catalog = $this->decode($contents);

        return DB::transaction(function () use ($catalog, $checksum, $publish) {
            $existing = DB::table('legal_catalog_versions')->where('checksum_sha256', $checksum)->value('id');
            $versionId = $existing ?? $this->insertVersion($catalog, $checksum);

            if ($publish) {
                DB::table('legal_catalog_versions')->where('id', $versionId)->update(['published_at' => now()]);
            }

            return [
                'version_id' => (int) $versionId,
                'jurisdiction' => $catalog['jurisdiction'],
                'created' => $existing === null,
                'published' => $publish,
                'checksum_sha256' => $checksum,
            ];
        });
    }

    /**
     * Latest published version of every jurisdiction, keyed by jurisdiction code.
     *
     * @return array<string, object{id: int, jurisdiction: string, checksum_sha256: string, published_at: string}>
     */
    public static function publishedVersions(): array
    {
        $versions = DB::select(<<<'SQL'
            SELECT DISTINCT ON (jurisdiction) id, jurisdiction, checksum_sha256, published_at
            FROM legal_catalog_versions
            WHERE published_at IS NOT NULL
            ORDER BY jurisdiction, published_at DESC, id DESC
            SQL);

        return array_column($versions, null, 'jurisdiction');
    }

    /**
     * Version id the API should answer from: the most recently published one.
     */
    public static function currentVersionId(string $jurisdiction): ?int
    {
        $id = DB::table('legal_catalog_versions')
            ->where('jurisdiction', $jurisdiction)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function decode(string $contents): array
    {
        try {
            $catalog = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Catalog is not valid JSON: '.$exception->getMessage(), 0, $exception);
        }
        if (! is_array($catalog) || ($catalog['format'] ?? null) !== self::FORMAT) {
            throw new InvalidArgumentException('Unsupported catalog format; expected '.self::FORMAT);
        }
        foreach (['jurisdiction', 'acts', 'areas', 'rules', 'species_lists', 'verified_rules'] as $key) {
            if (! array_key_exists($key, $catalog)) {
                throw new InvalidArgumentException("Catalog is missing \"{$key}\"");
            }
        }

        $jurisdiction = $catalog['jurisdiction'];
        if (! is_string($jurisdiction) || preg_match('/^[a-z]{2}$/', $jurisdiction) !== 1) {
            throw new InvalidArgumentException('Catalog jurisdiction must be a two-letter code');
        }
        $acts = array_column($catalog['acts'], null, 'id');
        $areas = array_column($catalog['areas'], null, 'id');
        if (! isset($areas[$jurisdiction])) {
            throw new InvalidArgumentException("Catalog has no root area \"{$jurisdiction}\"");
        }
        foreach ($areas as $code => $area) {
            if ($code !== $jurisdiction && ! str_starts_with((string) $code, $jurisdiction.':')) {
                throw new InvalidArgumentException("Area {$code} is not qualified with the catalog jurisdiction {$jurisdiction}");
            }
            foreach ($area['within'] ?? [] as $outer) {
                $outerJurisdiction = explode(':', (string) $outer, 2)[0];
                if ($outerJurisdiction === $jurisdiction || preg_match('/^[a-z]{2}(:[a-z0-9_]+)?$/', (string) $outer) !== 1) {
                    throw new InvalidArgumentException("Area {$code}: within {$outer} must be an area of another jurisdiction");
                }
            }
        }
        foreach ($catalog['rules'] as $rule) {
            $code = $rule['id'] ?? '?';
            if (! isset($acts[$rule['act'] ?? ''])) {
                throw new InvalidArgumentException("Rule {$code} points to an unknown act");
            }
            if (($acts[$rule['act']]['status'] ?? 'in_force') === 'revoked') {
                throw new InvalidArgumentException("Rule {$code} points to a revoked act");
            }
            foreach ($rule['basins'] ?? [] as $area) {
                if (! isset($areas[$area])) {
                    throw new InvalidArgumentException("Rule {$code} points to an unknown area {$area}");
                }
            }
        }

        return $catalog;
    }

    private function insertVersion(array $catalog, string $checksum): int
    {
        $verified = array_flip($catalog['verified_rules']);
        $versionId = DB::table('legal_catalog_versions')->insertGetId([
            'jurisdiction' => $catalog['jurisdiction'],
            'format' => $catalog['format'],
            'checksum_sha256' => $checksum,
            'act_count' => count($catalog['acts']),
            'rule_count' => count($catalog['rules']),
            'verified_rule_count' => count($verified),
            'imported_at' => now(),
        ]);

        $actIds = [];
        foreach ($catalog['acts'] as $act) {
            $actIds[$act['id']] = DB::table('legal_acts')->insertGetId([
                'catalog_version_id' => $versionId,
                'code' => $act['id'],
                'title' => $act['title'],
                'issuer' => $act['issuer'],
                'jurisdiction' => $act['jurisdiction'],
                'kind' => $act['kind'] ?? 'norm',
                'status' => $act['status'] ?? 'in_force',
                'published_on' => ($act['published'] ?? '') !== '' ? $act['published'] : null,
                'official_url' => ($act['official_url'] ?? '') !== '' ? $act['official_url'] : null,
                'read_from' => ($act['read_from'] ?? '') !== '' ? $act['read_from'] : null,
                'text_status' => $act['text_status'],
                'in_force_note' => $act['in_force_note'] ?? null,
                'source' => isset($catalog['sources'][$act['id']]) ? json_encode($catalog['sources'][$act['id']]) : null,
            ]);
        }

        $areaIds = [];
        foreach ($catalog['areas'] as $area) {
            $areaIds[$area['id']] = DB::table('legal_areas')->insertGetId([
                'catalog_version_id' => $versionId,
                'code' => $area['id'],
                'name' => $area['name'],
                'parent_code' => $area['parent'] ?? null,
                'definition' => $area['definition'],
                'geo_mapping' => $area['geo_mapping'],
                'within_codes' => json_encode(array_values($area['within'] ?? [])),
            ]);
        }

        foreach ($catalog['rules'] as $rule) {
            $ruleId = DB::table('legal_rules')->insertGetId([
                'catalog_version_id' => $versionId,
                'legal_act_id' => $actIds[$rule['act']],
                'code' => $rule['id'],
                'articles' => $rule['articles'],
                'period_kind' => $rule['period']['kind'],
                'period_start' => $rule['period']['start'] ?? null,
                'period_end' => $rule['period']['end'] ?? null,
                'species_scope' => $rule['species'],
                'species_list' => $rule['species_list'] ?? null,
                'scope' => $rule['scope'],
                'effect' => $rule['effect'],
                'summary' => $rule['summary'],
                'conditions' => json_encode($rule['conditions'] ?? [], JSON_UNESCAPED_UNICODE),
                'stretches' => json_encode($rule['stretches'] ?? [], JSON_UNESCAPED_UNICODE),
                'geo_mapping' => $rule['geo_mapping'] ?? null,
                'verified' => isset($verified[$rule['id']]),
            ]);
            DB::table('legal_rule_areas')->insert(array_map(
                fn (string $area) => ['legal_rule_id' => $ruleId, 'legal_area_id' => $areaIds[$area]],
                $rule['basins'],
            ));
            foreach (array_values($rule['evidence'] ?? []) as $position => $evidence) {
                DB::table('legal_rule_evidence')->insert([
                    'legal_rule_id' => $ruleId,
                    'position' => $position,
                    'segment' => $evidence['segment'],
                    'quote' => $evidence['quote'],
                ]);
            }
        }

        foreach ($catalog['species_lists'] as $list) {
            DB::table('legal_species_lists')->insert([
                'catalog_version_id' => $versionId,
                'legal_act_id' => $actIds[$list['act']],
                'code' => $list['id'],
                'payload' => json_encode($list, JSON_UNESCAPED_UNICODE),
            ]);
        }

        return $versionId;
    }
}
