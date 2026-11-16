<?php

namespace App\Http\Controllers;

use App\Legal\CatalogImporter;
use App\Legal\SpeciesIndex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Species cited by the published catalogs, to find a species by any of its
 * names before evaluating it. Listing a species here says nothing about where
 * it may be fished; see /api/fishing/species and /api/fishing/evaluate.
 */
class SpeciesController extends Controller
{
    public function index(Request $request, SpeciesIndex $index): JsonResponse
    {
        $input = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $versions = CatalogImporter::publishedVersions();
        if ($versions === []) {
            return self::unavailable();
        }
        $ids = array_map(fn ($version) => (int) $version->id, array_values($versions));
        $items = $index->search(trim($input['q']), $ids, (int) ($input['limit'] ?? 20));

        return response()->json([
            'query' => trim($input['q']),
            'items' => array_map(fn (array $taxon) => self::present($taxon), $items),
            'catalogs' => self::catalogs($versions),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(string $id, SpeciesIndex $index): JsonResponse
    {
        $versions = CatalogImporter::publishedVersions();
        if ($versions === []) {
            return self::unavailable();
        }
        $taxon = $index->find($id, array_map(fn ($version) => (int) $version->id, array_values($versions)));
        if ($taxon === null) {
            return response()->json(['error' => 'species_not_found', 'message' => 'Espécie não citada pelos catálogos publicados.'], 404);
        }

        return response()->json(self::present($taxon) + ['catalogs' => self::catalogs($versions)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    public static function present(array $taxon): array
    {
        return [
            'id' => $taxon['id'],
            'name' => $taxon['name'],
            'scientific_name' => $taxon['scientific_name'],
            'rank' => $taxon['rank'],
            'common_names' => $taxon['common_names'],
            'written_as' => $taxon['written_as'],
            'family' => $taxon['family'],
            'group' => $taxon['group'],
            'notes' => $taxon['notes'],
            'threatened' => array_map(fn (array $t) => [
                'jurisdiction' => $t['jurisdiction'], 'list' => $t['list'], 'act' => $t['act'],
                'category' => $t['category'], 'valid_from' => $t['valid_from'], 'areas' => $t['areas'],
            ], $taxon['threatened']),
            'lists' => array_map(fn (array $m) => [
                'jurisdiction' => $m['jurisdiction'], 'list' => $m['list'], 'act' => $m['act'],
                'areas' => $m['areas'], 'classification' => $m['classification'], 'entry' => $m['entry'],
            ], $taxon['matches']),
        ];
    }

    private static function catalogs(array $versions): array
    {
        return array_values(array_map(fn ($version) => [
            'jurisdiction' => $version->jurisdiction,
            'version_id' => (int) $version->id,
            'checksum_sha256' => $version->checksum_sha256,
        ], $versions));
    }

    private static function unavailable(): JsonResponse
    {
        return response()->json([
            'error' => 'catalog_unavailable',
            'message' => 'Nenhum catálogo jurídico publicado.',
        ], 503);
    }
}
