<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Enums\TransactionType;
use Illuminate\Support\Facades\DB;

class InsightService
{
  /**
  * Mendapatkan ringkasan pengeluaran untuk user.
  */
  public function getExpenseSummary(int $userId): array
  {
    $currentMonth = now()->month;
    $currentYear = now()->year;
    $lastMonth = now()->subMonth()->month;
    $lastMonthYear = now()->subMonth()->year;

    // Total pengeluaran bulan ini
    $expenseThisMonth = $this->getTotalExpense($userId, $currentMonth, $currentYear);

    // Total pengeluaran bulan lalu
    $expenseLastMonth = $this->getTotalExpense($userId, $lastMonth, $lastMonthYear);

    // Persentase perubahan
    $percentageChange = $expenseLastMonth > 0
    ? round((($expenseThisMonth - $expenseLastMonth) / $expenseLastMonth) * 100, 1)
    : 0;

    // Top 3 kategori pengeluaran bulan ini
    $topCategories = $this->getTopExpenseCategories($userId, $currentMonth, $currentYear, 3);

    // Ambil mata uang default (bisa dari wallet pertama user)
    $currency = $this->getUserCurrency($userId);

    return [
      'expense_this_month' => $expenseThisMonth,
      'expense_last_month' => $expenseLastMonth,
      'percentage_change' => $percentageChange,
      'top_categories' => $topCategories,
      'currency' => $currency,
    ];
  }

  /**
  * Menghitung total pengeluaran berdasarkan bulan dan tahun.
  */
  private function getTotalExpense(int $userId, int $month, int $year): float
  {
    return (float) Transaction::expense()
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->whereYear('transaction_date', $year)
    ->whereMonth('transaction_date', $month)
    ->sum(DB::raw('amount / 100'));
  }

  /**
  * Mendapatkan top kategori pengeluaran.
  */
  private function getTopExpenseCategories(int $userId, int $month, int $year, int $limit): array
  {
    return Transaction::expense()
    ->with('category')
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->whereYear('transaction_date', $year)
    ->whereMonth('transaction_date', $month)
    ->select('category_id', DB::raw('SUM(amount / 100) as total'))
    ->groupBy('category_id')
    ->orderByDesc('total')
    ->limit($limit)
    ->get()
    ->map(fn($item) => [
      'id' => $item->category->id,
      'name' => $item->category->name,
      'icon' => $item->category->icon,
      'color' => $item->category->color,
      'total' => (float) $item->total,
      'formatted' => 'Rp ' . number_format($item->total, 0, ',', '.')
    ])->toArray();
  }

  /**
  * Mendapatkan kode mata uang user (default dari wallet pertama).
  */
  private function getUserCurrency(int $userId): string
  {
    $wallet = \Modules\FinTech\Models\Wallet::where('user_id', $userId)->first();
    return $wallet ? $wallet->currency : config('fintech.default_currency', 'IDR');
  }
}