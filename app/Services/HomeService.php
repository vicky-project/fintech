<?php

namespace Modules\FinTech\Services;

use Carbon\Carbon;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\TransactionType;
use Modules\FinTech\Traits\HasUserCache;
use Illuminate\Support\Facades\DB;

class HomeService
{
  use HasUserCache;

  protected int $cacheTtl = 600; // 10 menit

  /**
  * Ambil semua data yang dibutuhkan halaman depan.
  */
  public function getHomeData($user): array
  {
    $suffix = 'home';

    return $this->rememberForUser($user->id, $suffix, $this->cacheTtl, function () use ($user) {
      // 1. Dompet aktif
      $wallets = Wallet::where('user_id', $user->id)
      ->where('is_active', true)
      ->get();

      if ($wallets->isEmpty()) {
        return $this->emptyData();
      }

      $currency = $wallets->first()->currency ?? 'IDR';
      $totalBalance = $wallets->sum(fn($w) => $w->getBalanceFloat());

      // 2. Income & expense bulan ini
      $currentMonthStart = now()->startOfMonth();

      $income = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->where('type', TransactionType::INCOME)
      ->where('transaction_date', '>=', $currentMonthStart)
      ->sum('amount') / 100;

      $expense = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->where('type', TransactionType::EXPENSE)
      ->where('transaction_date', '>=', $currentMonthStart)
      ->sum('amount') / 100;

      // 3. Trend bulan lalu (income & net)
      $lastMonthStart = now()->subMonth()->startOfMonth();
      $lastMonthEnd = now()->subMonth()->endOfMonth();

      $lastMonthIncome = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->where('type', TransactionType::INCOME)
      ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
      ->sum('amount') / 100;

      $lastMonthExpense = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->where('type', TransactionType::EXPENSE)
      ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
      ->sum('amount') / 100;

      $incomeChange = $lastMonthIncome > 0
      ? round((($income - $lastMonthIncome) / $lastMonthIncome) * 100, 1)
      : ($income > 0 ? 100 : 0);

      $expenseChange = $lastMonthExpense > 0
      ? round((($expense - $lastMonthExpense) / $lastMonthExpense) * 100, 1)
      : ($expense > 0 ? 100 : 0);

      $trend = [
        'current_month_income' => $income,
        'current_month_expense' => $expense,
        'last_month_income' => $lastMonthIncome,
        'last_month_expense' => $lastMonthExpense,
        'income_change' => $incomeChange,
        'expense_change' => $expenseChange,
      ];

      // 4. Weekly expense per kategori (pakai DB aggregate)
      $weeklyExpense = $this->getWeeklyExpense($user->id);

      // 5. Transaksi terbaru
      $recentTransactions = Transaction::with(['wallet', 'category'])
      ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->orderBy('transaction_date', 'desc')
      ->orderBy('id', 'desc')
      ->limit(5)
      ->get()
      ->map(fn($t) => [
        'id' => $t->id,
        'type' => $t->type->value,
        'category' => [
          'name' => $t->category->name,
          'icon' => $t->category->icon,
          'color' => $t->category->color,
        ],
        'amount' => $t->getAmountFloat(),
        'formatted_amount' => $t->getFormattedAmount(),
        'transaction_date' => $t->transaction_date->toDateString(),
        'wallet' => ['name' => $t->wallet->name],
      ]);

      // 6. Budget warnings (>=80%)
      $budgetWarnings = $this->getBudgetWarnings($user->id);

      return [
        'has_wallets' => true,
        'has_transactions' => $recentTransactions->isNotEmpty(),
        'total_balance' => $totalBalance,
        'total_income' => $income,
        'total_expense' => $expense,
        'weekly_expense' => $weeklyExpense,
        'recent_transactions' => $recentTransactions,
        'trend' => $trend,
        'budget_warnings' => $budgetWarnings,
        'currency' => $currency,
      ];
    });
  }

  public function getMonthlyComparisonData(int $userId): array
  {
    $suffix = 'home_monthly_comparison';

    return $this->rememberForUser($userId,
      $suffix,
      $this->cacheTtl,
      function () use ($userId) {
        $months = collect(range(5, 0))->map(fn($i) => now()->subMonths($i)->format('Y-m'));
        $data = [];

        foreach ($months as $month) {
          [$year,
            $monthNum] = explode('-', $month);
          $start = now()->setDate($year, $monthNum, 1)->startOfMonth();
          $end = now()->setDate($year, $monthNum, 1)->endOfMonth();

          $income = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $userId))
          ->where('type', TransactionType::INCOME)
          ->whereBetween('transaction_date', [$start, $end])
          ->sum('amount') / 100;

          $expense = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $userId))
          ->where('type', TransactionType::EXPENSE)
          ->whereBetween('transaction_date', [$start, $end])
          ->sum('amount') / 100;

          $data[] = [
            'month' => Carbon::createFromDate($year, $monthNum)->translatedFormat('M Y'),
            'income' => round($income, 2),
            'expense' => round($expense, 2),
          ];
        }

        return $data;
      });
  }

  // ─── helper ─────────────────────────────────────────

  protected function emptyData(): array
  {
    return [
      'has_wallets' => false,
      'has_transactions' => false,
      'total_balance' => 0,
      'total_income' => 0,
      'total_expense' => 0,
      'weekly_expense' => [],
      'recent_transactions' => [],
      'trend' => null,
      'budget_warnings' => [],
      'currency' => 'IDR',
    ];
  }

  protected function getWeeklyExpense(int $userId): array
  {
    $raw = DB::table('fintech_transactions')
    ->join('fintech_wallets',
      'fintech_transactions.wallet_id',
      '=',
      'fintech_wallets.id')
    ->where('fintech_wallets.user_id',
      $userId)
    ->where('fintech_transactions.type',
      TransactionType::EXPENSE)
    ->whereBetween('fintech_transactions.transaction_date',
      [
        now()->startOfWeek()->toDateString(),
        now()->endOfWeek()->toDateString(),
      ])
    ->select('fintech_transactions.category_id',
      DB::raw('SUM(fintech_transactions.amount) as total_raw'))
    ->groupBy('fintech_transactions.category_id')
    ->get();

    if ($raw->isEmpty()) return [];

    $categoryIds = $raw->pluck('category_id')->unique();
    $categories = Category::whereIn('id', $categoryIds)->get()->keyBy('id');

    return $raw->map(function ($item) use ($categories) {
      $cat = $categories[$item->category_id] ?? null;
      return [
        'label' => $cat?->name ?? 'Tanpa Kategori',
        'value' => (int) $item->total_raw / 100,
        'color' => $cat?->color ?? '#7986CB',
      ];
    })->values()->toArray();
  }

  protected function getBudgetWarnings(int $userId): array
  {
    return Budget::where('user_id', $userId)
    ->where('is_active', true)
    ->with(['category', 'wallet'])
    ->get()
    ->map(function ($b) {
      $percentage = $b->getPercentage(); // hanya sekali
      return [
        'id' => $b->id,
        'category' => [
          'name' => $b->category->name,
          'icon' => $b->category->icon,
          'color' => $b->category->color,
        ],
        'wallet' => $b->wallet ? ['name' => $b->wallet->name] : null,
        'percentage' => $percentage,
        'is_overspent' => $percentage >= 100,
        'is_near_limit' => $percentage >= 80 && $percentage < 100,
        'formatted_amount' => $b->getFormattedAmount(),
        'formatted_spending' => $b->getFormattedSpending(),
      ];
    })
    ->filter(fn($b) => $b['percentage'] >= 80)
    ->sortByDesc('percentage')
    ->take(3)
    ->values()
    ->toArray();
  }

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'home',
      'home_monthly_comparison'
    ];
  }
}