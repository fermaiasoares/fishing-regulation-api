<?php

use App\Http\Controllers\FishingEvaluationController;
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
