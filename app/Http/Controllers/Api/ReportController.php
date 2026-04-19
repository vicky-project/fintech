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
  * Data untuk Doughnut Chart (pengeluaran mingguan per kategori)
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

    $currency = $request->input('wallet_id')
    ? Wallet::find($request->wallet_id)->currency
    : 'IDR';

    $labels = [];
    $values = [];
    $colors = [];
    $totalRaw = 0;

    foreach ($rawData as $item) {
      $category = $item->category;
      $labels[] = $category->name;
      $values[] = $item->total_raw / 100;
      $colors[] = $category->color ?? '#7986CB';
      $totalRaw += $item->total_raw;
    }

    $totalMoney = Money::ofMinor($totalRaw,
      $currency);

    return response()->json([
      'success' => true,
      'data' => [
        'labels' => $labels,
        'values' => $values,
        'colors' => $colors,
        'total' => $totalMoney->getAmount()->toFloat(),
        'formatted_total' => $totalMoney->formatTo('id_ID'),
        'period' => [
          'start' => $startDate->toDateString(),
          'end' => $endDate->toDateString()
        ]
      ]
    ]);
  }

  /**
  * Data untuk Bar Chart (pengeluaran & pemasukan bulanan per kategori)
  */
  public function barMonthly(Request $request): JsonResponse
  {
    $request->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'year' => 'integer|min:2000|max:2100',
      'month' => 'integer|min:1|max:12'
    ]);

    $userId = $request->user()->id;
    $year = $request->input('year',
      now()->year);
    $month = $request->input('month',
      now()->month);

    $baseQuery = Transaction::with('category')
    ->whereYear('transaction_date',
      $year)
    ->whereMonth('transaction_date',
      $month)
    ->whereHas('wallet',
      function ($q) use ($userId, $request) {
        $q->where('user_id', $userId);
        if ($walletId = $request->input('wallet_id')) {
          $q->where('id', $walletId);
        }
      });

    $currency = $request->input('wallet_id')
    ? Wallet::find($request->wallet_id)->currency
    : 'IDR';

    // --- Expenses ---
    $expenseRaw = (clone $baseQuery)->expense()
    ->select('category_id',
      DB::raw('SUM(amount) as total_raw'))
    ->groupBy('category_id')
    ->get();

    $expenseLabels = [];
    $expenseValues = [];
    $expenseColors = [];
    $expenseTotalRaw = 0;

    foreach ($expenseRaw as $item) {
      $category = $item->category;
      $expenseLabels[] = $category->name;
      $expenseValues[] = $item->total_raw / 100;
      $expenseColors[] = $category->color ?? '#FF6384';
      $expenseTotalRaw += $item->total_raw;
    }
    $expenseTotal = Money::ofMinor($expenseTotalRaw,
      $currency);

    // --- Incomes ---
    $incomeRaw = (clone $baseQuery)->income()
    ->select('category_id',
      DB::raw('SUM(amount) as total_raw'))
    ->groupBy('category_id')
    ->get();

    $incomeLabels = [];
    $incomeValues = [];
    $incomeColors = [];
    $incomeTotalRaw = 0;

    foreach ($incomeRaw as $item) {
      $category = $item->category;
      $incomeLabels[] = $category->name;
      $incomeValues[] = $item->total_raw / 100;
      $incomeColors[] = $category->color ?? '#36A2EB';
      $incomeTotalRaw += $item->total_raw;
    }
    $incomeTotal = Money::ofMinor($incomeTotalRaw,
      $currency);

    return response()->json([
      'success' => true,
      'data' => [
        'expenses' => [
          'labels' => $expenseLabels,
          'values' => $expenseValues,
          'colors' => $expenseColors,
          'total' => $expenseTotal->getAmount()->toFloat(),
          'formatted_total' => $expenseTotal->formatTo('id_ID'),
        ],
        'incomes' => [
          'labels' => $incomeLabels,
          'values' => $incomeValues,
          'colors' => $incomeColors,
          'total' => $incomeTotal->getAmount()->toFloat(),
          'formatted_total' => $incomeTotal->formatTo('id_ID'),
        ],
        'period' => sprintf('%d-%02d', $year, $month)
      ]
    ]);
  }
}