<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\BudgetService;
use Modules\FinTech\Services\WalletService;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class BudgetController extends Controller
{
  use ResolvesTelegramUser;

  protected BudgetService $budgetService;

  public function __construct(BudgetService $budgetService) {
    $this->budgetService = $budgetService;
  }

  public function index(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $budgets = $this->budgetService->getBudgets($telegramUser->id);

    return view('fintech::web.budgets.index', compact('budgets'));
  }

  public function create(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = app(WalletService::class)->getUserWallets($telegramUser);
    $categories = \Modules\FinTech\Models\Category::active()->expense()->orderBy('name')->get();

    return view('fintech::web.budgets.form', compact('wallets', 'categories'));
  }

  public function store(Request $request) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);

    $validated = $request->validate([
      'category_id' => 'required|exists:fintech_categories,id',
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'amount' => 'required|numeric|gt:0',
      'period_type' => 'required|in:monthly,yearly',
    ]);

    $this->budgetService->createBudget($telegramUser, $validated);

    return redirect()->route('fintech.budgets.index')
    ->with('success', 'Budget berhasil dibuat.');
  }

  public function edit(Request $request, $id) {
    $budget = Budget::findOrFail($id);
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $wallets = app(WalletService::class)->getUserWallets($telegramUser);
    $categories = \Modules\FinTech\Models\Category::active()->expense()->orderBy('name')->get();

    return view('fintech::web.budgets.form', compact('budget', 'wallets', 'categories'));
  }

  public function update(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $budget = Budget::findOrFail($id);

    $validated = $request->validate([
      'category_id' => 'required|exists:fintech_categories,id',
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'amount' => 'required|numeric|gt:0',
      'period_type' => 'required|in:monthly,yearly',
    ]);

    $this->budgetService->updateBudget($telegramUser, $budget, $validated);

    return redirect()->route('fintech.budgets.index')
    ->with('success', 'Budget berhasil diperbarui.');
  }

  public function destroy(Request $request, $id) {
    $telegramUser = $this->getTelegramUser($request->telegram_id);
    $budget = Budget::findOrFail($id);
    $this->budgetService->deleteBudget($telegramUser, $budget);

    return redirect()->route('fintech.budgets.index')
    ->with('success', 'Budget dihapus.');
  }
}