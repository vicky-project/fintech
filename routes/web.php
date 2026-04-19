<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\FinTechController;

Route::prefix('apps')
->name('apps.')
->group(function () {
  Route::view('fintech', 'fintech::index')->name('fintech');
});