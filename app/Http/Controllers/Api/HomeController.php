<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Enums\TransactionType;
use Illuminate\Support\Facades\DB;

class HomeController extends Controller
{
  public function index(): JsonResponse
  {
    $user = request()->user();
    $wallets = Wallet::where('user_id', $user->id)->where('is_active', true)->get();

    // Total saldo semua dompet
    $totalBalance = $wallets->sum(fn($w) => $w->getBalanceFloat());

    // Total pemasukan dan pengeluaran (semua waktu)
    $income = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::INCOME)
    ->sum(DB::raw('amount / 100'));

    $expense = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::EXPENSE)
    ->sum(DB::raw('amount / 100'));

    // Pengeluaran mingguan per kategori (doughnut chart)
    $startDate = now()->startOfWeek();
    $endDate = now()->endOfWeek();
    $weeklyExpense = Transaction::with('category')
    ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->where('type', TransactionType::EXPENSE)
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->select('category_id', DB::raw('SUM(amount) as total_raw'))
    ->groupBy('category_id')
    ->get()
    ->map(function($item) {
      $category = $item->category;
      return [
        'label' => $category->name,
        'value' => (int) $item->total_raw / 100,
        'color' => $category->color ?? '#7986CB'
      ];
    });

    // 5 transaksi terbaru
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

    return response()->json([
      'success' => true,
      'data' => [
        'total_balance' => $totalBalance,
        'total_income' => $income,
        'total_expense' => $expense,
        'weekly_expense' => $weeklyExpense,
        'recent_transactions' => $recentTransactions,
        'currency' => $wallets->first()?->currency ?? 'IDR'
      ]
    ]);
  }
}