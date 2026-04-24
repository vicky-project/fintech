<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Wallet;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

class BudgetService
{
  protected int $cacheTtl = 3600;

  /**
  * Get all active budgets for user.
  */
  public function getBudgets(int $userId): array
  {
    $cacheKey = "budgets_user_{$userId}";

    return Cache::remember($cacheKey, $this->cacheTtl, function () use ($userId) {
      return Budget::where('user_id', $userId)
      ->with(['category', 'wallet'])
      ->where('is_active', true)
      ->get()
      ->map(fn($b) => $this->formatBudget($b))
      ->values()
      ->all();
    });
  }

  /**
  * Create a new budget.
  */
  public function createBudget(Authenticatable $user, array $data): Budget
  {
    $data['user_id'] = $user->id;
    $budget = Budget::create($data);
    self::clearBudgetCaches($user->id);
    return $budget;
  }

  /**
  * Update an existing budget.
  */
  public function updateBudget(Authenticatable $user, Budget $budget, array $data): Budget
  {
    if ($budget->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
    $budget->update($data);
    self::learBudgetCaches($user->id);
    return $budget;
  }

  /**
  * Delete a budget.
  */
  public function deleteBudget(Authenticatable $user, Budget $budget): void
  {
    if ($budget->user_id !== $user->id) {
      abort(403, 'Unauthorized');
    }
    $budget->delete();
    self::clearBudgetCaches($user->id);
  }

  /**
  * Format a single budget for API response.
  */
  protected function formatBudget(Budget $budget): array
  {
    return [
      'id' => $budget->id,
      'category' => [
        'id' => $budget->category->id,
        'name' => $budget->category->name,
        'icon' => $budget->category->icon,
        'color' => $budget->category->color,
      ],
      'wallet' => $budget->wallet ? [
        'id' => $budget->wallet->id,
        'name' => $budget->wallet->name,
      ] : null,
      'amount' => $budget->getAmountFloat(),
      'formatted_amount' => $budget->getFormattedAmount(),
      'period_type' => $budget->period_type->value,
      'period_label' => $budget->period_type->label(),
      'current_spending' => $budget->getCurrentSpending(),
      'formatted_spending' => 'Rp ' . number_format($budget->getCurrentSpending(), 0, ',', '.'),
      'percentage' => $budget->getPercentage(),
      'is_overspent' => $budget->isOverspent(),
      'is_near_limit' => $budget->isNearLimit(),
    ];
  }

  /**
  * Clear budget caches for a user.
  */
  public static function clearBudgetCaches(int $userId): void
  {
    Cache::forget("budgets_user_{$userId}");
  }
}