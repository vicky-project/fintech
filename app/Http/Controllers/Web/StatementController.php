<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\StatementService;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class StatementController extends Controller
{
  use ResolvesTelegramUser;

  protected StatementService $statementService;

  public function __construct(StatementService $statementService) {
    $this->statementService = $statementService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $statements = $this->statementService->getPaginatedStatements($telegramUser->id, 20);

    return view('fintech::web.statements.index', compact('statements'));
  }

  public function create(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = app(WalletService::class)->getUserWallets($telegramUser);

    return view('fintech::web.statements.upload', compact('wallets'));
  }

  public function store(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $validated = $request->validate([
      'file' => 'required|file|mimes:pdf,xls,xlsx,csv|max:10240',
      'password' => 'nullable|string|max:50',
      'wallet_id' => 'required|exists:fintech_wallets,id',
    ]);

    $result = $this->statementService->uploadStatement(
      $telegramUser->id,
      $request->file('file'),
      $request->input('password'),
      $validated['wallet_id']
    );

    return redirect()->route('fintech.statements.index')
    ->with('success', 'Statement berhasil diunggah dan diproses.');
  }

  public function show(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $preview = $this->statementService->previewStatement($telegramUser->id, $id);

    return view('fintech::web.statements.preview', compact('preview', 'id'));
  }

  public function destroy(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $this->statementService->deleteStatement($telegramUser->id, $id);

    return redirect()->route('fintech.statements.index')
    ->with('success', 'Statement dihapus.');
  }

  // Import action
  public function import(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $statement = \Modules\FinTech\Models\BankStatement::where('user_id', $telegramUser->id)->findOrFail($id);

    $validated = $request->validate([
      'transaction_ids' => 'required|array|min:1',
      'transaction_ids.*' => 'exists:fintech_statement_transactions,id',
    ]);

    $result = $this->statementService->importStatement(
      $telegramUser,
      $statement,
      $validated['transaction_ids']
    );

    return redirect()->route('fintech.statements.index')
    ->with('success', "{$result['imported']} transaksi berhasil diimpor, {$result['skipped']} dilewati.");
  }
}