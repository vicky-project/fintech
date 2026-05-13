<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\StatementType;
use Modules\FinTech\Enums\CategoryType;
use Modules\FinTech\Traits\HasUserCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class InsightService
{
  use HasUserCache;

  protected BudgetService $budgetService;
  protected CategorizationService $categorizationService;
  protected int $cacheTtl = 3600; // 1 jam untuk analisis utama

  public function __construct(BudgetService $budgetService, CategorizationService $categorizationService) {
    $this->budgetService = $budgetService;
    $this->categorizationService = $categorizationService;
  }

  /**
  * Mendapatkan analisis lengkap untuk user dengan caching.
  */
  public function getFullAnalysis(int $userId): array
  {
    $suffix = 'insights';

    return $this->rememberForUser($userId, $suffix, $this->cacheTtl, function () use ($userId) {
      return $this->computeAnalysis($userId);
    });
  }

  /**
  * Hapus semua cache insight untuk user tertentu.
  */
  public static function clearCache(int $userId): void
  {
    app(static::class)->clearUserCache($userId);
    // Hapus juga cache subscription
    app(static::class)->forgetUserCacheItem($userId, 'subscriptions');
  }

  /**
  * Dapatkan daftar langganan dengan cache 24 jam.
  */
  public function getCachedSubscriptions(int $userId): array
  {
    $suffix = 'subscriptions';
    return $this->rememberForUser($userId, $suffix, 86400, function () use ($userId) {
      $endDate = Carbon::now();
      $startDate = Carbon::now()->subMonths(6)->startOfMonth();
      $transactions = Transaction::expense()
      ->with('category')
      ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
      ->whereBetween('transaction_date', [$startDate, $endDate])
      ->get();
      return $this->detectSubscriptions($transactions);
    });
  }

  // ─── Private helper methods ─────────────────────────────────────────

  private function computeAnalysis(int $userId): array
  {
    $endDate = Carbon::now();
    $startDate = Carbon::now()->subMonths(6)->startOfMonth();
    $currency = $this->getUserCurrency($userId);

    $transactions = Transaction::expense()
    ->with('category')
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->get();

    $trend = $this->calculateMonthlyTrend($transactions);
    $currentMonth = Carbon::now()->month;
    $currentYear = Carbon::now()->year;
    $topCategories = $this->getTopCategories($transactions, $currentMonth, $currentYear, 5);
    $anomalies = $this->detectAnomalies($transactions);
    $subscriptions = $this->getCachedSubscriptions($userId); // pakai cache
    $spendingRatio = $this->calculateSpendingRatio($transactions);
    $projection = $this->projectNextMonthCashflow($userId, $transactions);
    $budgets = $this->budgetService->getBudgets($userId);
    $recommendations = $this->generateSmartRecommendations(
      $trend, $topCategories, $anomalies, $subscriptions, $spendingRatio,
      $budgets, $projection
    );

    return [
      'currency' => $currency,
      'trend' => $trend,
      'top_categories' => $topCategories,
      'anomalies' => $anomalies,
      'subscriptions' => $subscriptions,
      'spending_ratio' => $spendingRatio,
      'projection' => $projection,
      'recommendations' => $recommendations,
      'summary' => [
        'total_expense_this_month' => $trend['current_month_total'],
        'avg_expense_3months' => $trend['avg_last_3months'],
        'change_percentage' => $trend['change_percentage'],
      ],
      'budgets' => $budgets,
    ];
  }

  private function calculateMonthlyTrend($transactions): array
  {
    $monthly = [];
    for ($i = 5; $i >= 0; $i--) {
      $date = Carbon::now()->subMonths($i);
      $month = $date->month;
      $year = $date->year;
      $total = $transactions->filter(fn($t) =>
        $t->transaction_date->month === $month &&
        $t->transaction_date->year === $year
      )->sum(fn($t) => $t->getAmountFloat());

      $monthly[] = [
        'month' => $date->translatedFormat('M Y'),
        'total' => round($total, 2)
      ];
    }

    $current = end($monthly)['total'];
    $previous = $monthly[4]['total'] ?? 0;
    $avg3 = array_sum(array_column(array_slice($monthly, 3, 3), 'total')) / 3;

    return [
      'data' => $monthly,
      'current_month_total' => $current,
      'previous_month_total' => $previous,
      'avg_last_3months' => round($avg3, 2),
      'change_percentage' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : 0
    ];
  }

  private function getTopCategories($transactions, $month, $year, $limit): array
  {
    return $transactions
    ->filter(fn($t) => $t->transaction_date->month === $month && $t->transaction_date->year === $year)
    ->groupBy('category_id')
    ->map(fn($group) => [
      'category' => $group->first()->category,
      'total' => $group->sum(fn($t) => $t->getAmountFloat())
    ])
    ->sortByDesc('total')
    ->take($limit)
    ->values()
    ->map(fn($item) => [
      'id' => $item['category']->id,
      'name' => $item['category']->name,
      'icon' => $item['category']->icon,
      'color' => $item['category']->color,
      'total' => round($item['total'], 2),
      'formatted' => 'Rp ' . number_format($item['total'], 0, ',', '.')
    ])->toArray();
  }

  private function detectAnomalies($transactions): array
  {
    $anomalies = [];
    $grouped = $transactions->groupBy('category_id');

    foreach ($grouped as $catId => $catTrans) {
      $category = $catTrans->first()->category;
      $currentMonth = Carbon::now()->month;
      $currentYear = Carbon::now()->year;

      $thisMonth = $catTrans->filter(fn($t) =>
        $t->transaction_date->month === $currentMonth &&
        $t->transaction_date->year === $currentYear
      )->sum(fn($t) => $t->getAmountFloat());

      $monthlyTotals = $catTrans
      ->groupBy(fn($t) => $t->transaction_date->format('Y-m'))
      ->map(fn($group) => $group->sum(fn($t) => $t->getAmountFloat()))
      ->values()
      ->toArray();

      if (count($monthlyTotals) < 3) continue;

      $mean = array_sum($monthlyTotals) / count($monthlyTotals);
      $variance = 0;
      foreach ($monthlyTotals as $value) {
        $variance += pow($value - $mean, 2);
      }
      $variance /= count($monthlyTotals);
      $stdDev = sqrt($variance);

      if ($stdDev == 0) continue;

      $zScore = ($thisMonth - $mean) / $stdDev;

      if ($zScore > 2.0 && $thisMonth > $mean) {
        $percentageIncrease = $mean > 0
        ? round(($thisMonth - $mean) / $mean * 100, 1)
        : 100;

        $anomalies[] = [
          'category' => [
            'id' => $category->id,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color
          ],
          'this_month' => round($thisMonth, 2),
          'average' => round($mean, 2),
          'percentage_increase' => $percentageIncrease,
          'z_score' => round($zScore, 2),
          'formatted' => 'Rp ' . number_format($thisMonth, 0, ',', '.')
        ];
      }
    }

    usort($anomalies, fn($a, $b) => $b['z_score'] <=> $a['z_score']);
    return $anomalies;
  }

  /**
  * Deteksi langganan bulanan dengan bantuan CategorizationService.
  */
  private function detectSubscriptions($transactions): array
  {
    // Kategori yang secara default dianggap langganan
    $subscriptionCategoryNames = [
      'Langganan',
      'Subscription',
      'Premium',
      'Membership',
      'Streaming',
      'Berlangganan',
      'Subscription & Membership',
      'Langganan Aplikasi'
    ];
    $subscriptionCategoryIds = Category::whereIn('name', $subscriptionCategoryNames)->pluck('id')->toArray();

    $potential = $transactions->filter(function ($t) use ($subscriptionCategoryIds) {
      $desc = $t->description ?? '';
      $statementType = StatementType::DEBIT;

      $suggestedCategory = $this->categorizationService->categorize($desc, $statementType);
      if (!$suggestedCategory) {
        return false;
      }

      $isSubscription = in_array($suggestedCategory->id, $subscriptionCategoryIds) ||
      stripos($suggestedCategory->name, 'langganan') !== false ||
      stripos($suggestedCategory->name, 'subscription') !== false;

      if (!$isSubscription) {
        $subscriptionKeywords = [
          'langganan',
          'subscription',
          'premium',
          'member',
          'membership',
          'netflix',
          'spotify',
          'youtube',
          'disney',
          'hbo',
          'vidio',
          'mola',
          'catchplay',
          'canva',
          'adobe',
          'zoom',
          'dropbox',
          'icloud',
          'google one',
          'microsoft 365',
          'office 365',
          'gym',
          'fitness',
          'aplikasi',
          'software',
          'web hosting',
          'domain',
          'vps',
          'server'
        ];
        $lowerDesc = strtolower($desc);
        foreach ($subscriptionKeywords as $kw) {
          if (str_contains($lowerDesc, $kw)) {
            $isSubscription = true;
            break;
          }
        }
      }

      return $isSubscription;
    });

    $grouped = $potential->groupBy(function ($t) {
      return $t->category_id . '|' . ($t->description ?? '') . '|' . $t->getAmountFloat();
    });

    $subscriptions = [];

    foreach ($grouped as $key => $group) {
      if ($group->count() < 3) continue;

      $months = $group->map(fn($t) => $t->transaction_date->format('Y-m'))->unique()->sort()->values();
      if ($months->count() < 2) continue;

      $firstMonth = Carbon::parse($months->first());
      $lastMonth = Carbon::parse($months->last());
      $expectedMonths = $firstMonth->diffInMonths($lastMonth) + 1;
      $actualMonths = $months->count();
      if ($actualMonths / $expectedMonths >= 0.7) {
        $first = $group->first();
        $subscriptions[] = [
          'category' => [
            'id' => $first->category->id,
            'name' => $first->category->name,
            'icon' => $first->category->icon,
            'color' => $first->category->color,
          ],
          'description' => $first->description,
          'amount' => $first->getAmountFloat(),
          'formatted' => $first->getFormattedAmount(),
          'occurrences' => $group->count(),
          'last_date' => $group->max('transaction_date')->toDateString(),
          'frequency' => $this->detectFrequency($months),
        ];
      }
    }

    usort($subscriptions, fn($a, $b) => $b['occurrences'] <=> $a['occurrences']);
    return $subscriptions;
  }

  private function detectFrequency($months): string
  {
    if ($months->count() < 2) return 'irregular';
    $diffs = [];
    for ($i = 1; $i < $months->count(); $i++) {
      $diff = Carbon::parse($months[$i])->diffInDays(Carbon::parse($months[$i-1]));
      $diffs[] = $diff;
    }
    $avgDiff = array_sum($diffs) / count($diffs);
    if ($avgDiff >= 25 && $avgDiff <= 35) return 'monthly';
    if ($avgDiff >= 7 && $avgDiff <= 10) return 'weekly';
    return 'irregular';
  }

  private function calculateSpendingRatio($transactions): array
  {
    $currentMonth = Carbon::now()->month;
    $currentYear = Carbon::now()->year;

    $thisMonthTrans = $transactions->filter(fn($t) =>
      $t->transaction_date->month === $currentMonth &&
      $t->transaction_date->year === $currentYear
    );

    $total = $thisMonthTrans->sum(fn($t) => $t->getAmountFloat());
    if ($total == 0) return [
      'primary' => 0,
      'secondary' => 0,
      'tertiary' => 0,
      'primary_amount' => 0,
      'secondary_amount' => 0,
      'tertiary_amount' => 0
    ];

    $primary = $thisMonthTrans->filter(fn($t) =>
      in_array('kebutuhan_pokok', $t->category->metadata['tags'] ?? [])
    )->sum(fn($t) => $t->getAmountFloat());

    $secondary = $thisMonthTrans->filter(fn($t) =>
      in_array('sekunder', $t->category->metadata['tags'] ?? [])
    )->sum(fn($t) => $t->getAmountFloat());

    $tertiary = $thisMonthTrans->filter(fn($t) =>
      in_array('tersier', $t->category->metadata['tags'] ?? [])
    )->sum(fn($t) => $t->getAmountFloat());

    return [
      'primary' => round($primary / $total * 100, 1),
      'secondary' => round($secondary / $total * 100, 1),
      'tertiary' => round($tertiary / $total * 100, 1),
      'primary_amount' => round($primary, 2),
      'secondary_amount' => round($secondary, 2),
      'tertiary_amount' => round($tertiary, 2)
    ];
  }

  private function projectNextMonthCashflow(int $userId, $transactions): array
  {
    $last3Months = $transactions->filter(fn($t) =>
      $t->transaction_date->gte(Carbon::now()->subMonths(3)->startOfMonth())
    );

    $avgExpense = $last3Months->groupBy(fn($t) => $t->transaction_date->format('Y-m'))
    ->map->sum(fn($t) => $t->getAmountFloat())
    ->average() ?? 0;

    $avgIncome = Transaction::income()
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->whereBetween('transaction_date', [Carbon::now()->subMonths(3), Carbon::now()])
    ->get()
    ->groupBy(fn($t) => $t->transaction_date->format('Y-m'))
    ->map->sum(fn($t) => $t->getAmountFloat())
    ->average() ?? 0;

    return [
      'projected_expense' => round($avgExpense, 2),
      'projected_income' => round($avgIncome, 2),
      'projected_surplus' => round($avgIncome - $avgExpense, 2),
      'formatted_expense' => 'Rp ' . number_format($avgExpense, 0, ',', '.'),
      'formatted_income' => 'Rp ' . number_format($avgIncome, 0, ',', '.')
    ];
  }

  private function generateSmartRecommendations(
    array $trend,
    array $topCategories,
    array $anomalies,
    array $subscriptions,
    array $ratio,
    array $budgets,
    array $projection
  ): array {
    $recs = [];

    if (($trend['change_percentage'] ?? 0) > 30) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-exclamation-triangle',
        'title' => 'Pengeluaran Meningkat Drastis',
        'message' => 'Pengeluaran bulan ini naik ' . $trend['change_percentage'] . '% dibanding bulan lalu. Tinjau anggaran Anda.',
      ];
    }

    foreach ($anomalies as $anom) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-graph-up-arrow',
        'title' => 'Lonjakan pada ' . $anom['category']['name'],
        'message' => 'Pengeluaran untuk kategori ini naik ' . $anom['percentage_increase'] . '% dari rata‑rata 3 bulan. Cek apakah ada pembelian tidak biasa.',
      ];
    }

    $totalSubs = collect($subscriptions)->sum('amount');
    if ($totalSubs > 0) {
      $recs[] = [
        'type' => 'info',
        'icon' => 'bi-calendar-check',
        'title' => 'Langganan Bulanan Terdeteksi',
        'message' => 'Total langganan sekitar Rp ' . number_format($totalSubs, 0, ',', '.') . ' per bulan. Evaluasi apakah semua masih diperlukan.',
      ];
    }

    if (($ratio['tertiary'] ?? 0) > 40) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-piggy-bank',
        'title' => 'Kurangi Pengeluaran Tersier',
        'message' => $ratio['tertiary'] . '% pengeluaran untuk gaya hidup. Mengurangi bisa meningkatkan tabungan.',
      ];
    }

    if (!empty($topCategories[0]) && stripos($topCategories[0]['name'], 'makan') !== false) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-cup-hot',
        'title' => 'Hemat Biaya Makan',
        'message' => 'Kategori "' . $topCategories[0]['name'] . '" terbesar. Memasak di rumah bisa menghemat.',
      ];
    }

    foreach ($budgets as $budget) {
      if ($budget['is_overspent']) {
        $recs[] = [
          'type' => 'warning',
          'icon' => 'bi-exclamation-octagon',
          'title' => 'Budget Terlampaui: ' . $budget['category']['name'],
          'message' => 'Budget ' . $budget['category']['name'] . ' terlampaui (' . $budget['percentage'] . '%). Kurangi pengeluaran.',
        ];
      } elseif ($budget['is_near_limit']) {
        $recs[] = [
          'type' => 'warning',
          'icon' => 'bi-exclamation-triangle',
          'title' => 'Budget Hampir Habis: ' . $budget['category']['name'],
          'message' => 'Budget ' . $budget['category']['name'] . ' mencapai ' . $budget['percentage'] . '%. Hati‑hati.',
        ];
      }
    }

    if (($projection['projected_surplus'] ?? 0) < 0) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-graph-down',
        'title' => 'Proyeksi Defisit Bulan Depan',
        'message' => 'Berdasarkan 3 bulan terakhir, pengeluaran diproyeksikan melebihi pemasukan. Siapkan dana cadangan.',
      ];
    } elseif (($projection['projected_surplus'] ?? 0) > ($projection['projected_income'] ?? 0) * 0.3) {
      $recs[] = [
        'type' => 'success',
        'icon' => 'bi-check-circle',
        'title' => 'Ada Ruang untuk Menabung!',
        'message' => 'Surplus diproyeksikan Rp ' . number_format($projection['projected_surplus'], 0, ',', '.') . ' bulan depan. Tingkatkan tabungan atau investasi.',
      ];
    }

    if (empty($budgets)) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-plus-circle',
        'title' => 'Buat Budget Pertama Anda',
        'message' => 'Belum ada budget. Buat anggaran untuk mengontrol keuangan lebih baik.',
      ];
    }

    return array_slice($recs, 0, 5);
  }

  /**
  * Proyeksi arus kas untuk N hari ke depan.
  */
  public function getCashflowProjection(int $userId, int $days = 7): array
  {
    $suffix = "cashflow_projection_{$days}";

    return $this->rememberForUser($userId, $suffix, $this->cacheTtl, function () use ($userId, $days) {
      $walletQuery = Wallet::where('user_id', $userId)->where('is_active', true);
      $totalBalance = $walletQuery->sum('balance') / 100;

      $dailyExpenses = Transaction::expense()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
      ->where('transaction_date', '>', Carbon::now()->subDays(30))
      ->orderBy('transaction_date')
      ->get()
      ->groupBy(fn($t) => $t->transaction_date->toDateString())
      ->map(fn($group) => $group->sum(fn($t) => $t->getAmountFloat()));

      $ema = 0;
      $alpha = 0.3;
      $increasing = 0;
      $decreasing = 0;
      $prev = null;

      foreach ($dailyExpenses as $value) {
        if ($prev !== null) {
          if ($value > $prev) $increasing++;
          elseif ($value < $prev) $decreasing++;
        }
        $prev = $value;
        $ema = $ema == 0 ? $value : $alpha * $value + (1 - $alpha) * $ema;
      }

      $trendFactor = 1.0;
      if ($increasing > $decreasing) $trendFactor = 1.2;
      elseif ($decreasing > $increasing) $trendFactor = 0.9;

      $avgExpense = $dailyExpenses->average() ?: 0;
      $adjustedExpense = max($ema, $avgExpense) * $trendFactor;

      $avgIncome = Transaction::income()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
      ->where('transaction_date', '>', Carbon::now()->subDays(30))
      ->sum('amount') / 100 / 30;
      $conservativeIncome = $avgIncome * 0.7;

      $budgets = $this->budgetService->getBudgets($userId);
      $budgetBurden = 0;
      $now = Carbon::now();
      foreach ($budgets as $budget) {
        if ($budget['percentage'] >= 80 && $budget['period_type'] === 'monthly') {
          $remainingDays = $now->daysInMonth - $now->day + 1;
          $remainingSpending = max(0, $budget['amount'] - $budget['current_spending']);
          $budgetBurden += $remainingSpending / $remainingDays;
        }
      }

      $subscriptions = $this->getCachedSubscriptions($userId);
      $totalMonthly = collect($subscriptions)->sum('amount');
      $subscriptionDaily = $totalMonthly / 30;

      $dailyNet = $conservativeIncome - $adjustedExpense - $subscriptionDaily - $budgetBurden;
      $projectedBalance = $totalBalance + ($dailyNet * $days);

      return [
        'balance' => round($totalBalance, 2),
        'avg_daily_expense' => round($adjustedExpense, 2),
        'avg_daily_income' => round($conservativeIncome, 2),
        'subscription_burden' => round($subscriptionDaily, 2),
        'budget_burden' => round($budgetBurden, 2),
        'daily_net' => round($dailyNet, 2),
        'projected_balance' => round($projectedBalance, 2),
        'sufficient' => $projectedBalance >= 0,
        'trend' => $increasing > $decreasing ? 'up' : ($decreasing > $increasing ? 'down' : 'stable'),
      ];
    });
  }

  private function getUserCurrency(int $userId): string
  {
    return UserSetting::where('user_id',
      $userId)->value('default_currency') ?? config('fintech.default_currency',
      'IDR');
  }

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'insights',
      'cashflow_projection_7',
      'subscriptions',
    ];
  }
}