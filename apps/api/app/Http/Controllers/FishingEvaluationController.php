<?php

namespace App\Http\Controllers;

use App\Legal\CatalogUnavailable;
use App\Legal\FishingEvaluator;
use App\Legal\UnknownSpecies;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FishingEvaluationController extends Controller
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public function __invoke(Request $request, FishingEvaluator $evaluator): JsonResponse
    {
        $input = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'species' => ['required_without:species_id', 'nullable', 'string', 'min:2', 'max:120'],
            'species_id' => ['required_without:species', 'nullable', 'string', 'max:160'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $date = isset($input['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], self::TIMEZONE)
            : CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        try {
            $result = $evaluator->evaluate(
                (float) $input['latitude'],
                (float) $input['longitude'],
                isset($input['species']) ? trim($input['species']) : null,
                $date,
                $input['species_id'] ?? null,
            );
        } catch (UnknownSpecies) {
            return response()->json([
                'message' => 'Espécie não citada pelos catálogos publicados.',
                'errors' => ['species_id' => ['Espécie não citada pelos catálogos publicados; use GET /api/species para buscar o id.']],
            ], 422);
        } catch (CatalogUnavailable) {
            return response()->json([
                'status' => 'undetermined',
                'error' => 'catalog_unavailable',
                'message' => 'Nenhum catálogo jurídico publicado; não é possível avaliar.',
            ], 503);
        }

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
