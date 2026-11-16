<?php

namespace App\Http\Controllers;

use App\Legal\CatalogUnavailable;
use App\Legal\FishingEvaluator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Status of every species cited by the official lists that apply at a point. */
class AreaSpeciesController extends Controller
{
    public const STATUSES = [FishingEvaluator::PROHIBITED, FishingEvaluator::CONDITIONAL, FishingEvaluator::UNDETERMINED];

    public function __invoke(Request $request, FishingEvaluator $evaluator): JsonResponse
    {
        $request->merge(['status' => is_string($request->query('status')) ? array_filter(explode(',', $request->query('status'))) : $request->query('status')]);
        $input = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(self::STATUSES)],
        ]);

        $date = isset($input['date'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $input['date'], FishingEvaluationController::TIMEZONE)
            : CarbonImmutable::now(FishingEvaluationController::TIMEZONE)->startOfDay();

        try {
            $result = $evaluator->speciesAt((float) $input['latitude'], (float) $input['longitude'], $date, ($input['status'] ?? []) ?: null);
        } catch (CatalogUnavailable) {
            return response()->json([
                'error' => 'catalog_unavailable',
                'message' => 'Nenhum catálogo jurídico publicado; não é possível avaliar.',
            ], 503);
        }

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
