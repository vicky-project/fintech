<?php

namespace Modules\FinTech\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\TransactionType;
use Brick\Money\Money;

class ReportService
{
  protected int $cacheTtl = 3600; // 1 hour

  /**
  * Weekly report data.
  */
  public function getWeeklyReport(Request $request,
    int $userId): array
  {
    $year = $request->input('year',
      now()->year);
    $week = $request->input('week',
      now()->weekOfYear);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateWeeklyCacheKey($userId,
      $year,
      $week,
      $walletId);

    return Cache::remember($cacheKey,
      $this->cacheTtl,
      function () use ($userId, $year, $week, $walletId) {
        $startDate = now()->setISODate($year, $week)->startOfWeek();
        $endDate = now()->setISODate($year, $week)->endOfWeek();

        $query = $this->buildBaseQuery($userId, $walletId, $startDate, $endDate);

        $currency = $this->getCurrency($userId, $walletId);

        // Initialize daily data
        $dailyData = [];
        $currentDate = $startDate->copy();
        while ($currentDate <= $endDate) {
          $dailyData[$currentDate->toDateString()] = ['income' => 0,
            'expense' => 0];
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
          $amount = (int) $item->total_raw / 100;
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
          $labels[] = date('D', strtotime($date));
          $income[] = $values['income'];
          $expense[] = $values['expense'];
        }

        return [
          'labels' => $labels,
          'income' => $income,
          'expense' => $expense,
          'currency' => $currency,
        ];
      });
  }

  /**
  * Monthly report data.
  */
  public function getMonthlyReport(Request $request,
    int $userId): array
  {
    $year = $request->input('year',
      now()->year);
    $month = $request->input('month',
      now()->month);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateMonthlyCacheKey($userId,
      $year,
      $month,
      $walletId);

    return Cache::remember($cacheKey,
      $this->cacheTtl,
      function () use ($userId, $year, $month, $walletId) {
        $startDate = now()->setDate($year, $month, 1)->startOfDay();
        $endDate = now()->setDate($year, $month, 1)->endOfMonth()->endOfDay();

        $query = $this->buildBaseQuery($userId, $walletId, $startDate, $endDate);

        $currency = $this->getCurrency($userId, $walletId);

        $daysInMonth = $endDate->day;
        $labels = [];
        $income = array_fill(1, $daysInMonth, 0);
        $expense = array_fill(1, $daysInMonth, 0);

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

        return [
          'labels' => array_values($labels),
          'income' => array_values($income),
          'expense' => array_values($expense),
          'currency' => $currency,
        ];
      });
  }

  /**
  * Yearly report data.
  */
  public function getYearlyReport(Request $request,
    int $userId): array
  {
    $year = $request->input('year',
      now()->year);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateYearlyCacheKey($userId,
      $year,
      $walletId);

    return Cache::remember($cacheKey,
      $this->cacheTtl,
      function () use ($userId, $year, $walletId) {
        $startDate = now()->setDate($year, 1, 1)->startOfDay();
        $endDate = now()->setDate($year, 12, 31)->endOfDay();

        $query = $this->buildBaseQuery($userId, $walletId, $startDate, $endDate);

        $currency = $this->getCurrency($userId, $walletId);

        $labels = ['Jan',
          'Feb',
          'Mar',
          'Apr',
          'Mei',
          'Jun',
          'Jul',
          'Agu',
          'Sep',
          'Okt',
          'Nov',
          'Des'];
        $income = array_fill(1, 12, 0);
        $expense = array_fill(1, 12, 0);

        $rawData = $query->select(
          DB::raw('MONTH(transaction_date) as month'),
          'type',
          DB::raw('SUM(amount) as total_raw')
        )
        ->groupBy('month', 'type')
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

        return [
          'labels' => $labels,
          'income' => array_values($income),
          'expense' => array_values($expense),
          'currency' => $currency,
        ];
      });
  }

  /**
  * All years report data (total per year).
  */
  public function getAllYearsReport(Request $request,
    int $userId): array
  {
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateAllYearCacheKey($userId,
      $walletId);

    return Cache::remember($cacheKey,
      $this->cacheTtl,
      function () use ($userId, $walletId) {
        $query = Transaction::whereHas('wallet', function ($q) use ($userId, $walletId) {
          $q->where('user_id', $userId);
          if ($walletId) {
            $q->where('id', $walletId);
          }
        });

        $currency = $this->getCurrency($userId,
          $walletId);

        $rawData = $query->select(
          DB::raw('YEAR(transaction_date) as year'),
          'type',
          DB::raw('SUM(amount) as total_raw')
        )
        ->groupBy('year',
          'type')
        ->orderBy('year')
        ->get();

        $years = [];
        $incomeByYear = [];
        $expenseByYear = [];

        foreach ($rawData as $item) {
          $year = (int) $item->year;
          $amount = (int) $item->total_raw / 100;
          if (!in_array($year, $years)) {
            $years[] = $year;
          }
          if ($item->type === TransactionType::INCOME) {
            $incomeByYear[$year] = $amount;
          } elseif ($item->type === TransactionType::EXPENSE) {
            $expenseByYear[$year] = $amount;
          }
        }

        sort($years);
        $income = array_map(fn($y) => $incomeByYear[$y] ?? 0, $years);
        $expense = array_map(fn($y) => $expenseByYear[$y] ?? 0, $years);

        return [
          'labels' => $years,
          'income' => $income,
          'expense' => $expense,
          'currency' => $currency,
        ];
      });
  }

  /**
  * Doughnut chart data for weekly expenses by category.
  */
  public function getDoughnutWeeklyReport(Request $request,
    int $userId): array
  {
    $weekOffset = $request->input('week_offset',
      0);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateDoughnutCacheKey($userId,
      $weekOffset,
      $walletId);

    return Cache::remember($cacheKey,
      $this->cacheTtl,
      function () use ($userId, $weekOffset, $walletId) {
        $startDate = now()->subWeeks($weekOffset)->startOfWeek();
        $endDate = now()->subWeeks($weekOffset)->endOfWeek();

        $query = Transaction::expense()
        ->with('category')
        ->whereBetween('transaction_date', [$startDate, $endDate])
        ->whereHas('wallet', function ($q) use ($userId, $walletId) {
          $q->where('user_id', $userId);
          if ($walletId) {
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

        $currency = $this->getCurrency($userId,
          $walletId);

        $labels = [];
        $values = [];
        $colors = [];

        foreach ($rawData as $item) {
          $category = $item->category;
          $labels[] = $category->name;
          $values[] = (int) $item->total_raw / 100;
          $colors[] = $category->color ?? '#7986CB';
        }

        return [
          'labels' => $labels,
          'values' => $values,
          'colors' => $colors,
          'currency' => $currency,
        ];
      });
  }

  /**
  * Clear all report caches for a user (call this when transactions/wallets change).
  */
  public static function clearReportCaches(int $userId): void
  {
    try {
      $redis = Cache::store('redis')->getRedis();
      $keys = $redis->keys("report_*_{$userId}_*");
      foreach ($keys as $key) {
        Cache::forget($key);
      }
    } catch (\Exception $e) {
      Cache::flush();
    }
  }

  // ------------------------------------------------------------------------
  // Helper methods
  // ------------------------------------------------------------------------

  /**
  * Build base query for transactions.
  */
  protected function buildBaseQuery(int $userId, ?int $walletId, $startDate, $endDate) {
    $query = Transaction::whereBetween('transaction_date', [$startDate, $endDate])
    ->whereHas('wallet', function ($q) use ($userId,
      $walletId) {
      $q->where('user_id',
        $userId);
      if ($walletId) {
        $q->where('id', $walletId);
      }
    });
    return $query;
  }

  /**
  * Get currency code.
  */
  protected function getCurrency(int $userId,
    ?int $walletId): string
  {
    if ($walletId) {
      $wallet = Wallet::where('id', $walletId)->where('user_id', $userId)->first();
      return $wallet ? $wallet->currency : config('fintech.default_currency', 'IDR');
    }
    return config('fintech.default_currency', 'IDR');
  }

  /**
  * Generate cache key for weekly report.
  */
  protected function generateWeeklyCacheKey(int $userId, int $year, int $week, ?int $walletId): string
  {
    return "report_weekly_{$userId}_{$year}_{$week}_" . ($walletId ?? 'all');
  }


  /**
  * Generate cache key for monthly report.
  */
  protected function generateMonthlyCacheKey(int $userId, int $year, int $month, ?int $walletId): string
  {
    return "report_monthly_{$userId}_{$year}_{$month}_" . ($walletId ?? 'all');
  }

  /**
  * Generate cache key for yearly report.
  */
  protected function generateYearlyCacheKey(int $userId, int $year, ?int $walletId): string
  {
    return "report_yearly_{$userId}_{$year}_" . ($walletId ?? 'all');
  }

  /**
  * Generate cache key for doughnut weekly report.
  */
  protected function generateDoughnutCacheKey(int $userId, int $weekOffset, ?int $walletId): string
  {
    return "report_doughnut_{$userId}_{$weekOffset}_" . ($walletId ?? 'all');
  }

  protected function generateAllYearCacheKey(int $userId, ?int $walletId): string
  {
    return "report_all_years_{$userId}_" . ($walletId ?? 'all');
  }
}