<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\Api\ {
  WalletController,
  TransactionController,
  CategoryController,
  CategorySuggestionController,
  CurrencyController,
  ReportController
};

/*
|--------------------------------------------------------------------------
| FinTech API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->prefix('fintech')->name('fintech.')->group(function () {

  // ==================== WALLETS ====================
  Route::apiResource('wallets', WalletController::class)->names('wallets');

  // ==================== CATEGORIES ====================
  Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');

  // ==================== CURRENCIES ====================
  Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies.index');

  // ==================== TRANSACTIONS ====================
  // Soft delete & trashed routes (diletakkan sebelum resource untuk menghindari konflik)
  Route::get('transactions/trashed', [TransactionController::class, 'trashed'])->name('transactions.trashed');
  Route::post('transactions/{id}/restore', [TransactionController::class, 'restore'])->name('transactions.restore');
  Route::delete('transactions/{id}/force', [TransactionController::class, 'forceDelete'])->name('transactions.force-delete');
  Route::post('transfer', [TransactionController::class, 'transfer'])->name('transfer');

  // Resource utama transaksi (hanya method yang dibutuhkan)
  Route::apiResource('transactions', TransactionController::class)
  ->only(['index', 'store', 'show', 'destroy', 'update'])
  ->names('transactions');

  // ==================== REPORTS ====================
  Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('weekly', [ReportController::class, 'weekly'])->name('weekly');
    Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
    Route::get('yearly', [ReportController::class, 'yearly'])->name('yearly');
    Route::get('doughnut-weekly', [ReportController::class, 'doughnutWeekly'])->name('doughnut-weekly');
  });

  Route::post('category-suggestions', [CategorySuggestionController::class, 'store']);
});