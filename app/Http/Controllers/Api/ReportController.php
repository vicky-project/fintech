<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\TransactionType;
use Brick\Money\Money;

class ReportController extends Controller
{
  /**
  * Laporan mingguan (untuk bar chart).
  * Mengembalikan data pemasukan dan pengeluaran per hari dalam satu minggu.
  */
  public function weekly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100',
      'week' => 'integer|min:1|max:53'
    ]);

    $userId = $request->user()->id;
    $year = $request->input('year', now()->year);
    $week = $request->input('week', now()->weekOfYear);

    $startDate = now()->setISODate($year, $week)->startOfWeek();
    $endDate = now()->setISODate($year, $week)->endOfWeek();

    $query = Transaction::with('category')
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->whereHas('wallet', function ($q) use ($userId, $request) {
      $q->where('user_id', $userId);
      if ($walletId = $request->input('wallet_id')) {
        $q->where('id', $walletId);
      }
    });

    $currency = $this->getCurrency($request);

    // Ambil data per hari
    $dailyData = [];
    $currentDate = $startDate->copy();
    while ($currentDate <= $endDate) {
      $dailyData[$currentDate->toDateString()] = [
        'income' => 0,
        'expense' => 0
      ];
      $currentDate->addDay();
    }

    $rawData = $query->select(
      'transaction_date',
      'type',
      DB::raw('SUM(amount) as total_raw')
    )
    ->groupBy('transaction_date', 'type')
    ->get();

    foreach ($rawData as $item) {
      $date = $item->transaction_date->toDateString();
      $amount = (int) $item->total_raw / 100; // Konversi dari satuan terkecil ke float
      if ($item->type === TransactionType::INCOME) {
        $dailyData[$date]['income'] += $amount;
      } elseif ($item->type === TransactionType::EXPENSE) {
        $dailyData[$date]['expense'] += $amount;
      }
    }

    $labels = [];
    $income = [];
    $expense = [];

    foreach ($dailyData as $date => $values) {
      $labels[] = date('D', strtotime($date)); // Singkatan hari (Sen, Sel, ...)
      $income[] = $values['income'];
      $expense[] = $values['expense'];
    }

    return response()->json([
      'success' => true,
      'data' => [
        'labels' => $labels,
        'income' => $income,
        'expense' => $expense,
        'currency' => $currency,
      ]
    ]);
  }

  /**
  * Laporan bulanan (untuk bar chart).
  * Mengembalikan data pemasukan dan pengeluaran per hari dalam satu bulan.
  */
  public function monthly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100',
      'month' => 'integer|min:1|max:12'
    ]);

    $userId = $request->user()->id;
    $year = $request->input('year', now()->year);
    $month = $request->input('month', now()->month);

    $startDate = now()->setDate($year, $month, 1)->startOfDay();
    $endDate = now()->setDate($year, $month, 1)->endOfMonth()->endOfDay();

    $query = Transaction::with('category')
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->whereHas('wallet', function ($q) use ($userId, $request) {
      $q->where('user_id', $userId);
      if ($walletId = $request->input('wallet_id')) {
        $q->where('id', $walletId);
      }
    });

    $currency = $this->getCurrency($request);

    // Data per hari
    $daysInMonth = $endDate->day;
    $labels = [];
    $income = array_fill(1,
      $daysInMonth,
      0);
    $expense = array_fill(1,
      $daysInMonth,
      0);

    for ($i = 1; $i <= $daysInMonth; $i++) {
      $labels[] = (string) $i;
    }

    $rawData = $query->select(
      DB::raw('DAY(transaction_date) as day'),
      'type',
      DB::raw('SUM(amount) as total_raw')
    )
    ->groupBy('day', 'type')
    ->get();

    foreach ($rawData as $item) {
      $day = (int) $item->day;
      $amount = (int) $item->total_raw / 100;
      if ($item->type === TransactionType::INCOME) {
        $income[$day] += $amount;
      } elseif ($item->type === TransactionType::EXPENSE) {
        $expense[$day] += $amount;
      }
    }

    return response()->json([
      'success' => true,
      'data' => [
        'labels' => array_values($labels),
        'income' => array_values($income),
        'expense' => array_values($expense),
        'currency' => $currency,
      ]
    ]);
  }

  /**
  * Laporan tahunan (untuk bar chart).
  * Mengembalikan data pemasukan dan pengeluaran per bulan dalam satu tahun.
  */
  public function yearly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100'
    ]);

    $userId = $request->user()->id;
    $year = $request->input('year', now()->year);

    $startDate = now()->setDate($year, 1, 1)->startOfDay();
    $endDate = now()->setDate($year, 12, 31)->endOfDay();

    $query = Transaction::with('category')
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->whereHas('wallet', function ($q) use ($userId, $request) {
      $q->where('user_id', $userId);
      if ($walletId = $request->input('wallet_id')) {
        $q->where('id', $walletId);
      }
    });

    $currency = $this->getCurrency($request);

    $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $income = array_fill(1,
      12,
      0);
    $expense = array_fill(1,
      12,
      0);

    $rawData = $query->select(
      DB::raw('MONTH(transaction_date) as month'),
      'type',
      DB::raw('SUM(amount) as total_raw')
    )
    ->groupBy('month',
      'type')
    ->get();

    foreach ($rawData as $item) {
      $month = (int) $item->month;
      $amount = (int) $item->total_raw / 100;
      if ($item->type === TransactionType::INCOME) {
        $income[$month] += $amount;
      } elseif ($item->type === TransactionType::EXPENSE) {
        $expense[$month] += $amount;
      }
    }

    return response()->json([
      'success' => true,
      'data' => [
        'labels' => $labels,
        'income' => array_values($income),
        'expense' => array_values($expense),
        'currency' => $currency,
      ]
    ]);
  }

  /**
  * Data untuk doughnut chart pengeluaran mingguan per kategori.
  */
  public function doughnutWeekly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'week_offset' => 'integer|min:0'
    ]);

    $userId = $request->user()->id;
    $weekOffset = $request->input('week_offset', 0);

    $startDate = now()->subWeeks($weekOffset)->startOfWeek();
    $endDate = now()->subWeeks($weekOffset)->endOfWeek();

    $query = Transaction::expense()
    ->with('category')
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->whereHas('wallet', function ($q) use ($userId, $request) {
      $q->where('user_id', $userId);
      if ($walletId = $request->input('wallet_id')) {
        $q->where('id', $walletId);
      }
    });

    $rawData = $query->select(
      'category_id',
      DB::raw('SUM(amount) as total_raw')
    )
    ->groupBy('category_id')
    ->orderByDesc('total_raw')
    ->get();

    $currency = $this->getCurrency($request);

    $labels = [];
    $values = [];
    $colors = [];

    foreach ($rawData as $item) {
      $category = $item->category;
      $labels[] = $category->name;
      $values[] = (int) $item->total_raw / 100;
      $colors[] = $category->color ?? '#7986CB';
    }

    return response()->json([
      'success' => true,
      'data' => [
        'labels' => $labels,
        'values' => $values,
        'colors' => $colors,
        'currency' => $currency,
      ]
    ]);
  }

  /**
  * Mendapatkan kode mata uang yang digunakan.
  */
  private function getCurrency(Request $request): string
  {
    if ($walletId = $request->input('wallet_id')) {
      $wallet = Wallet::find($walletId);
      return $wallet ? $wallet->currency : 'IDR';
    }
    return 'IDR';
  }
}