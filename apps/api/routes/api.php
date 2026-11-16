<?php

use App\Http\Controllers\AreaSpeciesController;
use App\Http\Controllers\FishingEvaluationController;
use App\Http\Controllers\SpeciesController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::selectOne('SELECT PostGIS_Version()');
        Redis::connection()->ping();
    } catch (Throwable $exception) {
        report($exception);

        return response()->json(['status' => 'unavailable'], 503);
    }

    return response()->json(['status' => 'ok']);
});

Route::post('/fishing/evaluate', FishingEvaluationController::class)
    ->middleware('throttle:60,1')
    ->name('fishing.evaluate');

Route::get('/fishing/species', AreaSpeciesController::class)
    ->middleware('throttle:60,1')
    ->name('fishing.species');

Route::get('/species', [SpeciesController::class, 'index'])
    ->middleware('throttle:120,1')
    ->name('species.index');

Route::get('/species/{id}', [SpeciesController::class, 'show'])
    ->where('id', '[a-z0-9-]+')
    ->middleware('throttle:120,1')
    ->name('species.show');
