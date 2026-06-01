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
  Route::get('/', [Web\HomeController::class, 'index'])->name('home');

  // ==================== WALLETS ====================
  Route::resource('wallets', Web\WalletController::class);

  // ==================== TRANSACTIONS ====================
  Route::resource('transactions', Web\TransactionController::class);
  Route::post('transactions/bulk-destroy', [Web\TransactionController::class, 'bulkDestroy'])->name('transactions.bulk-destroy');
  Route::get('transactions/trashed', [Web\TransactionController::class, 'trashed'])->name('transactions.trashed');
  Route::post('transactions/{id}/restore', [Web\TransactionController::class, 'restore'])->name('transactions.restore');
  Route::delete('transactions/{id}/force', [Web\TransactionController::class, 'forceDelete'])->name('transactions.force-delete');

  // ==================== TRANSFERS ====================
  Route::resource('transfers', Web\TransferController::class);
  Route::get('transfers/trashed', [Web\TransferController::class, 'trashed'])->name('transfers.trashed');
  Route::post('transfers/{id}/restore', [Web\TransferController::class, 'restore'])->name('transfers.restore');
  Route::delete('transfers/{id}/force', [Web\TransferController::class, 'forceDelete'])->name('transfers.force-delete');

  // ==================== BUDGETS ====================
  Route::resource('budgets', Web\BudgetController::class);

  // ==================== REPORTS ====================
  Route::get('reports', [Web\ReportController::class, 'index'])->name('reports.index');

  // ==================== STATEMENTS ====================
  Route::resource('statements', Web\StatementController::class)->except(['edit', 'update']);
  Route::post('statements/{statement}/import', [Web\StatementController::class, 'import'])->name('statements.import');

  // ==================== INSIGHTS ====================
  Route::get('insights', [Web\InsightController::class, 'index'])->name('insights.index');

  // ==================== ZAKAT & TAX ====================
  Route::get('zakat', [Web\ZakatController::class, 'index'])->name('zakat.index');

  // ==================== SETTINGS ====================
  Route::get('settings', [Web\SettingController::class, 'index'])->name('settings');
  Route::put('settings', [Web\SettingController::class, 'update'])->name('settings.update');
});