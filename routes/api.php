<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\Api\WalletController;
use Modules\FinTech\Http\Controllers\Api\TransactionController;
use Modules\FinTech\Http\Controllers\Api\CategoryController;
use Modules\FinTech\Http\Controllers\Api\ReportController;

Route::middleware(['auth:sanctum'])->prefix('fintech')->group(function () {
  // Wallet routes
  Route::apiResource('wallets', WalletController::class);

  // Category routes (read-only untuk user)
  Route::get('categories', [CategoryController::class, 'index']);

  // Transaction routes
  Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'show']);

  // Report routes
  Route::prefix('reports')->group(function () {
    Route::get('doughnut-weekly', [ReportController::class, 'doughnutWeekly']);
    Route::get('bar-monthly', [ReportController::class, 'barMonthly']);
  });
});