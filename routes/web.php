<?php

use Illuminate\Support\Facades\Route;
use Modules\FinTech\Http\Controllers\FinTechController;
use Modules\FinTech\Http\Controllers\Web;

Route::prefix('apps')
->name('apps.')
->group(function () {
  Route::view('fintech', 'fintech::index')->name('fintech');
});

$middlewares = ['auth', 'web'];
if (class_exists($tgConnected = \Modules\Telegram\Http\Middleware\EnsureTelegramConnected::class)) {
  $middlewares[] = $tgConnected;
}

Route::middleware($middlewares)->prefix('fintech')->name('fintech.')->group(function() {
  // ==================== DASHBOARD ====================
  Route::get('/', [HomeController::class, 'index'])->name('home');

  // ==================== WALLETS ====================
  Route::resource('wallets', WalletController::class);

  // ==================== TRANSACTIONS ====================
  Route::resource('transactions', TransactionController::class);
  Route::post('transactions/bulk-destroy', [TransactionController::class, 'bulkDestroy'])->name('transactions.bulk-destroy');
  Route::get('transactions/trashed', [TransactionController::class, 'trashed'])->name('transactions.trashed');
  Route::post('transactions/{id}/restore', [TransactionController::class, 'restore'])->name('transactions.restore');
  Route::delete('transactions/{id}/force', [TransactionController::class, 'forceDelete'])->name('transactions.force-delete');

  // ==================== TRANSFERS ====================
  Route::resource('transfers', TransferController::class);
  Route::get('transfers/trashed', [TransferController::class, 'trashed'])->name('transfers.trashed');
  Route::post('transfers/{id}/restore', [TransferController::class, 'restore'])->name('transfers.restore');
  Route::delete('transfers/{id}/force', [TransferController::class, 'forceDelete'])->name('transfers.force-delete');

  // ==================== BUDGETS ====================
  Route::resource('budgets', BudgetController::class);

  // ==================== REPORTS ====================
  Route::get('reports', [ReportController::class, 'index'])->name('reports.index');

  // ==================== SETTINGS ====================
  Route::get('settings', [SettingController::class, 'index'])->name('settings');
  Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
});