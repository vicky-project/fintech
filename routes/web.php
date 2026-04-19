<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\FinTechController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('finteches', FinTechController::class)->names('fintech');
});
