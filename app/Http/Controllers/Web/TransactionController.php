<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\TransactionService;
use Modules\FinTech\Services\WalletService;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Models\Transaction;
use Nnjeim\World\Models\Currency;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class TransactionController extends Controller
{
  use ResolvesTelegramUser;

  protected TransactionService $service;
  protected WalletService $walletService;

  public function __construct(TransactionService $service, WalletService $walletService) {
    $this->service = $service;
    $this->walletService = $walletService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $filters = [
      'wallet_id' => $request->input('wallet_id', ''),
      'type' => $request->input('type', ''),
      'month' => $request->input('month', ''),
    ];

    $result = $this->service->getTransactions($telegramUser, $filters, 20);
    $wallets = $this->walletService->getUserWallets($telegramUser);

    $defaultCurrency = UserSetting::where('user_id', $telegramUser->id)->value('default_currency') ?? 'IDR';
    $currency = Currency::where('code', $defaultCurrency)->first();

    return view('fintech::web.transactions.index', [
      'result' => $result,
      'wallets' => $wallets,
      'filters' => $filters,
      'symbol' => $currency->symbol ?? 'Rp',
    ]);
  }

  public function create(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = $this->walletService->getUserWallets($telegramUser);
    $categories = Category::active()->orderBy('name')->get();

    return view('fintech::web.transactions.form', compact('wallets', 'categories'));
  }

  public function store(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $validated = $request->validate([
      'wallet_id' => 'required|exists:fintech_wallets,id',
      'category_id' => 'required|exists:fintech_categories,id',
      'amount' => 'required|numeric|gt:0',
      'transaction_date' => 'required|date',
      'type' => 'required|in:income,expense',
      'description' => 'nullable|string|max:500',
    ]);

    $this->service->createTransaction($telegramUser, $validated);

    return redirect()->route('fintech.transactions.index')
    ->with('success', 'Transaksi berhasil ditambahkan.');
  }

  public function show($id) {
    $transaction = Transaction::with(['wallet', 'category'])->findOrFail($id);
    return view('fintech::web.transactions.show', compact('transaction'));
  }

  public function edit(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $transaction = Transaction::findOrFail($id);
    $wallets = $this->walletService->getUserWallets($telegramUser);
    $categories = Category::active()->orderBy('name')->get();

    return view('fintech::web.transactions.form', compact('wallets', 'categories', 'transaction'));
  }

  public function update(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $validated = $request->validate([
      'category_id' => 'required|exists:fintech_categories,id',
      'amount' => 'required|numeric|gt:0',
      'transaction_date' => 'required|date',
      'type' => 'required|in:income,expense',
      'description' => 'nullable|string|max:500',
    ]);

    $transaction = Transaction::findOrFail($id);
    $this->service->updateTransaction($telegramUser, $transaction, $validated);

    return redirect()->route('fintech.transactions.index')
    ->with('success', 'Transaksi berhasil diperbarui.');
  }

  public function destroy(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $transaction = Transaction::findOrFail($id);
    $this->service->deleteTransaction($telegramUser, $transaction);

    return redirect()->route('fintech.transactions.index')
    ->with('success', 'Transaksi dipindahkan ke tempat sampah.');
  }

  public function bulkDestroy(Request $request) {
    $validated = $request->validate([
      'wallet_id' => 'required|exists:fintech_wallets,id',
      'month' => 'required|date_format:Y-m',
    ]);

    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $count = $this->service->bulkDeleteTransactions(
      $telegramUser,
      $validated['wallet_id'],
      $validated['month']
    );

    return redirect()->route('fintech.transactions.index')
    ->with('success', "{$count} transaksi dipindahkan ke tempat sampah.");
  }
}