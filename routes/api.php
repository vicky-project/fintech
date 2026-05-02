<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\Api\ {
  BackupController,
  BudgetController,
  CategoryController,
  CategorySuggestionController,
  CurrencyController,
  ExportController,
  GoogleOAuthController,
  HomeController,
  InsightController,
  NotificationController,
  ReportController,
  SearchController,
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

Route::middleware(['auth:sanctum', 'pin.session'])->prefix('fintech')->name('fintech.')->group(function () {
  Route::get('home-summary', [HomeController::class, 'index']);

  // ==================== CATEGORIES ====================
  Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');

  // ==================== CURRENCIES ====================
  Route::get('currencies', [CurrencyController::class, 'index'])->name('currencies.index');

  Route::post('backup/send', [BackupController::class, 'send']);

  // ==================== TRANSACTIONS ====================
  Route::get('transactions/trashed', [TransactionController::class, 'trashed'])->name('transactions.trashed');
  Route::post('transactions/bulk-destroy', [TransactionController::class, 'bulkDestroy'])->name('transactions.bulk-destroy');
  Route::post('transactions/{id}/restore', [TransactionController::class, 'restore'])->name('transactions.restore');
  Route::delete('transactions/{id}/force', [TransactionController::class, 'forceDelete'])->name('transactions.force-delete');

  Route::get('transfers/trashed', [TransferController::class, 'trashed']);
  Route::post('transfers/{id}/restore', [TransferController::class, 'restore']);
  Route::delete('transfers/{id}/force', [TransferController::class, 'forceDelete']);

  Route::post('category-suggestions', [CategorySuggestionController::class, 'store']);

  Route::get('insights/full', [InsightController::class, 'fullAnalysis']);

  // ==================== WALLETS ====================
  Route::apiResource('wallets', WalletController::class)->names('wallets');

  Route::apiResource('transactions', TransactionController::class)
  ->only(['index', 'store', 'show', 'destroy', 'update'])
  ->names('transactions');

  Route::apiResource('transfers', TransferController::class)->except(['show']);

  Route::apiResource('budgets', BudgetController::class)->except(['show']);

  // ==================== REPORTS ====================
  Route::middleware(['auth:sanctum', 'pin.session'])
  ->prefix('reports')
  ->name('reports.')
  ->group(function () {
    Route::get('weekly', [ReportController::class, 'weekly'])->name('weekly');
    Route::get('monthly', [ReportController::class, 'monthly'])->name('monthly');
    Route::get('yearly', [ReportController::class, 'yearly'])->name('yearly');
    Route::get('doughnut-weekly', [ReportController::class, 'doughnutWeekly'])->name('doughnut-weekly');
    Route::get('category-summary', [ReportController::class, 'categorySummary'])->name('category-summary');
    Route::get('category-table', [ReportController::class, 'categoryTable']);
    Route::get('all_years', [ReportController::class, 'allYears'])->name('all_years');
  });

  Route::prefix('statements')
  ->middleware(['auth:sanctum', 'pin.session'])
  ->group(function() {
    Route::get('', [StatementController::class, 'index']);
    Route::post('upload', [StatementController::class, 'upload']);
    Route::put("transactions/{transaction}/category", [StatementController::class, 'updateCategory']);
    Route::get('{statement}/preview', [StatementController::class, 'preview']);
    Route::post('{statement}/import', [StatementController::class, 'import']);
    Route::delete('{statement}', [StatementController::class, 'destroy']);
  });

  Route::prefix('notifications')
  ->middleware(['auth:sanctum', 'pin.session'])
  ->group(function() {
    Route::get('', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('read-all', [NotificationController::class, 'markAllRead']);
    Route::post('{id}/read', [NotificationController::class, 'markRead']);
  });
});


Route::middleware(['auth:sanctum'])->prefix('fintech')->name('fintech.')->group(function() {
  Route::get('search', [SearchController::class, 'global']);
  Route::post('exports', [ExportController::class, 'export']);
  Route::get('settings', [SettingController::class, 'show'])->middleware('pin.session');
  Route::put('settings', [SettingController::class, 'update']);
  Route::post('settings/verify-pin', [SettingController::class, 'verifyPin'])->middleware('throttle:10,1');
  Route::get('/oauth/google/status', [GoogleOAuthController::class, 'status']);
  Route::get('/oauth/google/redirect', [GoogleOAuthController::class, 'redirect']);
});

Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback']);