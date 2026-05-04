<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Services\BudgetService;
use Illuminate\Validation\Rule;

class BudgetController extends Controller
{
  protected BudgetService $budgetService;

  public function __construct(BudgetService $budgetService) {
    $this->budgetService = $budgetService;
  }

  public function index(Request $request): JsonResponse
  {
    $budgets = $this->budgetService->getBudgets($request->user()->id);

    return response()->json([
      'success' => true,
      'data' => $budgets
    ]);
  }

  public function store(Request $request): JsonResponse
  {
    $validated = $request->validate([
      'category_id' => [
        'required',
        'exists:fintech_categories,id',
        Rule::unique('fintech_budgets')->where(function ($query) use ($request) {
          return $query->where('user_id', $request->user()->id)
          ->where('period_type', $request->period_type)
          ->when($request->wallet_id, fn($q) => $q->where('wallet_id', $request->wallet_id));
        }),
      ],
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'amount' => 'required|numeric|min:1000',
      'period_type' => 'required|in:monthly,yearly',
    ]);

    $budget = $this->budgetService->createBudget($request->user(), $validated);

    return response()->json([
      'success' => true,
      'message' => 'Budget berhasil dibuat.',
      'data' => $budget
    ], 201);
  }

  public function update(Request $request, Budget $budget): JsonResponse
  {
    $validated = $request->validate([
      'amount' => 'required|numeric|min:1000',
      'is_active' => 'sometimes|boolean',
    ]);

    $budget = $this->budgetService->updateBudget($request->user(), $budget, $validated);

    return response()->json([
      'success' => true,
      'message' => 'Budget diupdate.',
      'data' => $budget
    ]);
  }

  public function destroy(Request $request, Budget $budget): JsonResponse
  {
    $this->budgetService->deleteBudget($request->user(), $budget);

    return response()->json([
      'success' => true,
      'message' => 'Budget dihapus.'
    ]);
  }
}