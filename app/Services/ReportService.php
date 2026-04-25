<?php

namespace Modules\FinTech\Services;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\TransactionType;

class ReportService
{
  protected int $cacheTtl = 3600; // 1 hour

  /**
  * Cek apakah cache driver mendukung tags.
  */
  private static function supportsTags(): bool
  {
    return Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
  }

  /**
  * Simpan cache dengan tags jika didukung, jika tidak pakai cache polos.
  * Tag sudah dikunci: ['report', "user_{userId}"].
  */
  private function rememberWithFallback(int $userId, string $cacheKey, int $ttl, callable $callback): mixed
  {
    if (self::supportsTags()) {
      return Cache::tags(['report', "user_{$userId}"])->remember($cacheKey, $ttl, $callback);
    }
    return Cache::remember($cacheKey, $ttl, $callback);
  }

  /**
  * Clear semua cache laporan untuk user tertentu.
  */
  public static function clearReportCaches(int $userId): void
  {
    try {
      if (self::supportsTags()) {
        Cache::tags(['report', "user_{$userId}"])->flush();
      }
      // Untuk driver non-taggable, kita tidak bisa menghapus spesifik.
    } catch (\Exception $e) {
      \Log::warning('Failed to clear report caches: ' . $e->getMessage());
    }
  }

  /**
  * Weekly report data.
  */
  public function getWeeklyReport(Request $request, int $userId): array
  {
    $year = (int) $request->input('year', now()->year);
    $week = (int) $request->input('week', now()->weekOfYear);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateWeeklyCacheKey($userId, $year, $week, $walletId);

    return $this->rememberWithFallback($userId, $cacheKey, $this->cacheTtl, function () use ($userId, $year, $week, $walletId) {
      $startDate = now()->setISODate($year, $week)->startOfWeek();
      $endDate = now()->setISODate($year, $week)->endOfWeek();

      $query = $this->buildBaseQuery($userId, $walletId, $startDate, $endDate);
      $currency = $this->getCurrency($userId, $walletId);

      $dailyData = [];
      $currentDate = $startDate->copy();
      while ($currentDate <= $endDate) {
        $dailyData[$currentDate->toDateString()] = ['income' => 0,
          'expense' => 0];
        $currentDate->addDay();
      }

      $rawData = $query->select('transaction_date', 'type', DB::raw('SUM(amount) as total_raw'))
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
    $year = (int) $request->input('year',
      now()->year);
    $month = (int) $request->input('month',
      now()->month);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateMonthlyCacheKey($userId,
      $year,
      $month,
      $walletId);

    return $this->rememberWithFallback($userId,
      $cacheKey,
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

        $rawData = $query->select(DB::raw('DAY(transaction_date) as day'), 'type', DB::raw('SUM(amount) as total_raw'))
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
    $year = (int) $request->input('year',
      now()->year);
    $walletId = $request->input('wallet_id');

    $cacheKey = $this->generateYearlyCacheKey($userId,
      $year,
      $walletId);

    return $this->rememberWithFallback($userId,
      $cacheKey,
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

        $rawData = $query->select(DB::raw('MONTH(transaction_date) as month'), 'type', DB::raw('SUM(amount) as total_raw'))
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

    return $this->rememberWithFallback($userId,
      $cacheKey,
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

        $rawData = $query->select(DB::raw('YEAR(transaction_date) as year'),
          'type',
          DB::raw('SUM(amount) as total_raw'))
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
  * Get category table summary for all years.
  */
  public function getCategoryTable(Request $request,
    int $userId): array
  {
    $walletId = $request->input('wallet_id');
    $type = $request->input('type',
      'expense');

    $cacheKey = $this->generateCategoryTableCache($userId,
      $walletId,
      $type);

    return $this->rememberWithFallback($userId,
      $cacheKey,
      $this->cacheTtl,
      function () use ($userId, $walletId, $type) {
        $query = Transaction::whereHas('wallet', fn($q) => $q->where('user_id', $userId));

        if ($walletId) {
          $query->where('wallet_id', $walletId);
        }

        if ($type === 'income') {
          $query->income();
        } else {
          $query->expense();
        }

        $rawData = $query->with('category')
        ->select('category_id', DB::raw('YEAR(transaction_date) as year'), DB::raw('SUM(amount) as total_raw'))
        ->groupBy('category_id', 'year')
        ->orderBy('category_id')
        ->orderBy('year')
        ->get();

        $currency = $this->getCurrency($userId, $walletId);

        $years = $rawData->pluck('year')->unique()->sort()->values();

        $categories = [];
        foreach ($rawData as $item) {
          $catId = $item->category_id;
          if (!isset($categories[$catId])) {
            $categories[$catId] = [
              'id' => $item->category->id,
              'name' => $item->category->name,
              'icon' => $item->category->icon,
              'color' => $item->category->color,
              'data' => [],
            ];
          }
          $categories[$catId]['data'][$item->year] = (int) $item->total_raw / 100;
        }

        $totals = [];
        foreach ($years as $year) {
          $totals[$year] = collect($categories)->sum(fn($cat) => $cat['data'][$year] ?? 0);
        }

        return [
          'years' => $years,
          'categories' => array_values($categories),
          'totals' => $totals,
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

    return $this->rememberWithFallback($userId,
      $cacheKey,
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

        $rawData = $query->select('category_id',
          DB::raw('SUM(amount) as total_raw'))
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
  * Get category summary for a given period (expense or income).
  */
  public function getCategorySummary(Request $request, int $userId): array
  {
    $walletId = $request->input('wallet_id');
    $periodType = $request->input('period_type', 'monthly');
    $year = (int) $request->input('year', now()->year);
    $month = (int) $request->input('month', now()->month);
    $type = $request->input('type', 'expense');

    $cacheKey = sprintf("report_category_%d_%s_%d_%d_%s_%s", $userId, $periodType, $year, $month, $walletId ?? 'all', $type);

    return $this->rememberWithFallback($userId, $cacheKey, $this->cacheTtl, function () use ($userId,
      $walletId,
      $periodType,
      $year,
      $month,
      $type) {
      $query = Transaction::whereHas('wallet',
        fn($q) => $q->where('user_id', $userId));

      if ($walletId) {
        $query->where('wallet_id', $walletId);
      }

      if ($periodType === 'monthly') {
        $query->whereYear('transaction_date', $year)->whereMonth('transaction_date', $month);
      } elseif ($periodType === 'yearly') {
        $query->whereYear('transaction_date', $year);
      }

      if ($type === 'income') {
        $query->income();
      } else {
        $query->expense();
      }

      $rawData = $query->with('category')
      ->select('category_id', DB::raw('SUM(amount) as total_raw'))
      ->groupBy('category_id')
      ->orderByDesc('total_raw')
      ->get();

      $currency = $this->getCurrency($userId, $walletId);

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
        'total' => array_sum($values),
      ];
    });
  }

  // ------------------------------------------------------------------------
  // Helper methods
  // ------------------------------------------------------------------------

  protected function buildBaseQuery(int $userId,
    ?int $walletId,
    $startDate,
    $endDate): \Illuminate\Database\Eloquent\Builder
  {
    return Transaction::whereBetween('transaction_date',
      [$startDate,
        $endDate])
    ->whereHas('wallet',
      function ($q) use ($userId, $walletId) {
        $q->where('user_id', $userId);
        if ($walletId) {
          $q->where('id', $walletId);
        }
      });
  }

  protected function getCurrency(int $userId,
    ?int $walletId): string
  {
    if ($walletId) {
      $wallet = Wallet::where('id', $walletId)->where('user_id', $userId)->first();
      return $wallet ? $wallet->currency : config('fintech.default_currency', 'IDR');
    }
    return config('fintech.default_currency', 'IDR');
  }

  protected function generateWeeklyCacheKey(int $userId, int $year, int $week, ?int $walletId): string
  {
    return sprintf("report_weekly_%d_%d_%d_%s", $userId, $year, $week, $walletId ?? 'all');
  }

  protected function generateMonthlyCacheKey(int $userId, int $year, int $month, ?int $walletId): string
  {
    return sprintf("report_monthly_%d_%d_%d_%s", $userId, $year, $month, $walletId ?? 'all');
  }

  protected function generateYearlyCacheKey(int $userId, int $year, ?int $walletId): string
  {
    return sprintf("report_yearly_%d_%d_%s", $userId, $year, $walletId ?? 'all');
  }

  protected function generateDoughnutCacheKey(int $userId, int $weekOffset, ?int $walletId): string
  {
    return sprintf("report_doughnut_%d_%d_%s", $userId, $weekOffset, $walletId ?? 'all');
  }

  protected function generateAllYearCacheKey(int $userId, ?int $walletId): string
  {
    return sprintf("report_all_years_%d_%s", $userId, $walletId ?? 'all');
  }

  protected function generateCategoryTableCache(int $userId, ?int $walletId, ?string $type): string
  {
    return sprintf("report_category_table_%d_%s_%s", $userId, $walletId ?? 'all', $type ?? 'expense');
  }
}