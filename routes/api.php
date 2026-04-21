<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\Api\ {
  CategoryController,
  CategorySuggestionController,
  CurrencyController,
  HomeController,
  InsightController,
  ReportController,
  SettingController,
  StatementController,
  TransactionController,
  TransferController,
  WalletController
};

/*
|--------------------------------------------------------------------------
| FinTech API Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->prefix('fintech')->name('fintech.')->group(function () {
  Route::get('home-summary', [HomeController::class, 'index']);

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
  // Resource utama transaksi (hanya method yang dibutuhkan)
  Route::apiResource('transactions', TransactionController::class)
  ->only(['index', 'store', 'show', 'destroy', 'update'])
  ->names('transactions');

  Route::apiResource('transfers', TransferController::class)->except(['show']);
  Route::get('transfers/trashed', [TransferController::class, 'trashed']);
  Route::post('transfers/{id}/restore', [TransferController::class, 'restore']);
  Route::delete('transfers/{id}/force', [TransferController::class, 'forceDelete']);

  // ==================== REPORTS ====================
  Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('daily', [ReportController::class, 'daily'])->name('daily');
    Route::get('weekly', [ReportController::class, 'weekly'])->name('weekly');
    Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
    Route::get('yearly', [ReportController::class, 'yearly'])->name('yearly');
    Route::get('doughnut-weekly', [ReportController::class, 'doughnutWeekly'])->name('doughnut-weekly');
  });

  Route::post('category-suggestions', [CategorySuggestionController::class, 'store']);

  Route::get('settings', [SettingController::class, 'show']);
  Route::put('settings', [SettingController::class, 'update']);

  Route::get('insights/full', [InsightController::class, 'fullAnalysis']);

  Route::prefix('statements')->group(function() {
    Route::post('/upload', [StatementController::class, 'upload']);
    Route::put("/transactions/{transaction}/category", [StatementController::class, 'updateCategory']);
    Route::get('/{statement}/preview', [StatementController::class, 'preview']);
    Route::post('/{statement}/import', [StatementController::class, 'import']);
  });
});