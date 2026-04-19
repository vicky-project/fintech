<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\FinTechController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('finteches', FinTechController::class)->names('fintech');
});
