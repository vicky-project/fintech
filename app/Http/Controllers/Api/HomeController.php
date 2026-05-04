<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Budget;
use Modules\FinTech\Enums\TransactionType;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
  public function index(): JsonResponse
  {
    $user = request()->user();

    // 1. Dompet aktif
    $wallets = Wallet::where('user_id', $user->id)
    ->where('is_active', true)
    ->get();

    // Jika belum punya dompet, kembalikan data minimal
    if ($wallets->isEmpty()) {
      return response()->json([
        'success' => true,
        'data' => [
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
        ]
      ]);
    }

    $currency = $wallets->first()->currency ?? 'IDR';

    // 2. Total saldo
    $totalBalance = $wallets->sum(fn($w) => $w->getBalanceFloat());

    // 3. Income & expense bulan ini
    $currentMonthStart = now()->startOfMonth();

    $income = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::INCOME)
    ->where('transaction_date', '>=', $currentMonthStart)
    ->sum(DB::raw('amount / 100'));

    $expense = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::EXPENSE)
    ->where('transaction_date', '>=', $currentMonthStart)
    ->sum(DB::raw('amount / 100'));

    // 4. Pengeluaran bulan lalu (untuk trend)
    $lastMonthStart = now()->subMonth()->startOfMonth();
    $lastMonthEnd = now()->subMonth()->endOfMonth();

    $lastMonthExpense = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::EXPENSE)
    ->whereBetween('transaction_date', [$lastMonthStart, $lastMonthEnd])
    ->sum(DB::raw('amount / 100'));

    $changePercentage = $lastMonthExpense > 0
    ? round((($expense - $lastMonthExpense) / $lastMonthExpense) * 100, 1)
    : ($expense > 0 ? 100 : 0);

    $trend = [
      'current_month_total' => $expense,
      'last_month_total' => $lastMonthExpense,
      'change_percentage' => $changePercentage,
    ];

    // 5. Pengeluaran mingguan per kategori (untuk doughnut chart)
    $weeklyExpense = Transaction::with('category')
    ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::EXPENSE)
    ->whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()])
    ->select('category_id', DB::raw('SUM(amount) as total_raw'))
    ->groupBy('category_id')
    ->get()
    ->map(function ($item) {
      $category = $item->category;
      return [
        'label' => $category->name,
        'value' => (int) $item->total_raw / 100,
        'color' => $category->color ?? '#7986CB',
      ];
    })
    ->values();

    // 6. Transaksi terbaru (5)
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

    // 7. Peringatan budget (>=80%)
    $budgetWarnings = Budget::where('user_id', $user->id)
    ->where('is_active', true)
    ->with(['category', 'wallet'])
    ->get()
    ->filter(fn($b) => $b->getPercentage() >= 80)
    ->sortByDesc(fn($b) => $b->getPercentage())
    ->take(3)
    ->map(function ($b) {
      return [
        'id' => $b->id,
        'category' => [
          'name' => $b->category->name,
          'icon' => $b->category->icon,
          'color' => $b->category->color,
        ],
        'wallet' => $b->wallet ? ['name' => $b->wallet->name] : null,
        'percentage' => $b->getPercentage(),
        'is_overspent' => $b->isOverspent(),
        'is_near_limit' => $b->isNearLimit(),
        'formatted_amount' => $b->getFormattedAmount(),
        'formatted_spending' => $b->getFormattedSpending(),
      ];
    })
    ->values();

    return response()->json([
      'success' => true,
      'data' => [
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
      ]
    ]);
  }
}