<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\WalletService;
use Modules\FinTech\Models\Wallet;

class WalletController extends Controller
{
  use ResolvesTelegramUser;

  protected WalletService $walletService;

  public function __construct(WalletService $walletService) {
    $this->walletService = $walletService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = $this->walletService->getUserWallets($telegramUser);

    return view('fintech::web.wallets.index', compact('wallets'));
  }

  public function create() {
    return view('fintech::web.wallets.form', ['wallet' => null]);
  }

  public function store(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $validated = $request->validate([
      'name' => 'required|string|max:255',
      'currency' => 'required|string|size:3',
      'description' => 'nullable|string|max:500',
      'initial_balance' => 'nullable|numeric|min:0',
    ]);

    $this->walletService->createWallet($telegramUser, $validated);

    return redirect()->route('fintech.wallets.index')
    ->with('success', 'Dompet berhasil dibuat.');
  }

  public function show(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallet = Wallet::findOrFail($id);
    $detail = $this->walletService->getWalletDetail($telegramUser, $wallet);

    return view('fintech::web.wallets.show', compact('wallet', 'detail'));
  }

  public function edit($id) {
    $wallet = Wallet::findOrFail($id);
    return view('fintech::web.wallets.form', compact('wallet'));
  }

  public function update(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallet = Wallet::findOrFail($id);

    $validated = $request->validate([
      'name' => 'required|string|max:255',
      'description' => 'nullable|string|max:500',
      'is_active' => 'boolean',
    ]);

    $this->walletService->updateWallet($telegramUser, $wallet, $validated);

    return redirect()->route('fintech.wallets.index')
    ->with('success', 'Dompet berhasil diperbarui.');
  }

  public function destroy(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallet = Wallet::findOrFail($id);
    $this->walletService->deleteWallet($telegramUser, $wallet);

    return redirect()->route('fintech.wallets.index')
    ->with('success', 'Dompet dihapus.');
  }
}