<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\TransferService;
use Modules\FinTech\Services\WalletService;
use Modules\FinTech\Models\Transfer;

class TransferController extends Controller
{
  use ResolvesTelegramUser;

  protected TransferService $transferService;
  protected WalletService $walletService;

  public function __construct(TransferService $transferService, WalletService $walletService) {
    $this->transferService = $transferService;
    $this->walletService = $walletService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $filters = [
      'wallet_id' => $request->input('wallet_id'),
    ];

    $result = $this->transferService->getTransfers($telegramUser, $filters, 20);
    $wallets = $this->walletService->getUserWallets($telegramUser);

    return view('fintech.transfers.index', [
      'transfers' => $result['data'],
      'pagination' => $result['pagination'],
      'wallets' => $wallets,
      'filters' => $filters,
    ]);
  }

  public function create(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = $this->walletService->getUserWallets($telegramUser);

    return view('fintech.transfers.form', compact('wallets'));
  }

  public function store(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $validated = $request->validate([
      'from_wallet_id' => 'required|exists:fintech_wallets,id',
      'to_wallet_id' => 'required|exists:fintech_wallets,id|different:from_wallet_id',
      'amount' => 'required|numeric|gt:0',
      'transfer_date' => 'required|date',
      'description' => 'nullable|string|max:500',
    ]);

    $this->transferService->createTransfer($telegramUser, $validated);

    return redirect()->route('fintech.transfers.index')
    ->with('success', 'Transfer berhasil dibuat.');
  }

  public function edit(Request $request, $id) {
    $transfer = Transfer::with(['fromWallet', 'toWallet'])->findOrFail($id);
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = $this->walletService->getUserWallets($telegramUser);

    return view('fintech.transfers.form', compact('transfer', 'wallets'));
  }

  public function update(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $transfer = Transfer::findOrFail($id);

    $validated = $request->validate([
      'amount' => 'required|numeric|gt:0',
      'transfer_date' => 'required|date',
      'description' => 'nullable|string|max:500',
    ]);

    $this->transferService->updateTransfer($telegramUser, $transfer, $validated);

    return redirect()->route('fintech.transfers.index')
    ->with('success', 'Transfer berhasil diperbarui.');
  }

  public function destroy(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $transfer = Transfer::findOrFail($id);
    $this->transferService->deleteTransfer($telegramUser, $transfer);

    return redirect()->route('fintech.transfers.index')
    ->with('success', 'Transfer dipindahkan ke tempat sampah.');
  }

  // Trash, restore, force delete bisa ditambahkan sesuai route
}