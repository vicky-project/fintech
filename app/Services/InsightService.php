<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Traits\HasUserCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class InsightService
{
  use HasUserCache;

  protected BudgetService $budgetService;
  protected int $cacheTtl = 3600; // 1 jam

  public function __construct(BudgetService $budgetService) {
    $this->budgetService = $budgetService;
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
  * Hapus cache insight untuk user tertentu.
  */
  public static function clearCache(int $userId): void
  {
    app(static::class)->clearUserCache($userId);
  }

  // ─── Private helper methods (tidak berubah) ──────────────────

  private function computeAnalysis(int $userId): array
  {
    $endDate = Carbon::now();
    $startDate = Carbon::now()->subMonths(6)->startOfMonth();
    $currency = $this->getUserCurrency($userId);

    // Ambil semua transaksi pengeluaran 6 bulan terakhir
    $transactions = Transaction::expense()
    ->with('category')
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->whereBetween('transaction_date', [$startDate, $endDate])
    ->get();

    // 1. Tren Pengeluaran Bulanan (6 bulan)
    $trend = $this->calculateMonthlyTrend($transactions);

    // 2. Top Kategori Bulan Ini
    $currentMonth = Carbon::now()->month;
    $currentYear = Carbon::now()->year;
    $topCategories = $this->getTopCategories($transactions, $currentMonth, $currentYear, 5);

    // 3. Anomali / Lonjakan Pengeluaran
    $anomalies = $this->detectAnomalies($transactions);

    // 4. Deteksi Langganan (transaksi berulang dengan nominal sama)
    $subscriptions = $this->detectSubscriptions($transactions);

    // 5. Rasio Kebutuhan (pokok vs sekunder vs tersier)
    $spendingRatio = $this->calculateSpendingRatio($transactions);

    // 6. Prediksi Arus Kas Bulan Depan
    $projection = $this->projectNextMonthCashflow($userId, $transactions);

    // 7. Ambil data budget
    $budgets = $this->budgetService->getBudgets($userId);

    // 8. Hasilkan Rekomendasi Cerdas
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

      // 1. Pengeluaran bulan ini
      $thisMonth = $catTrans->filter(fn($t) =>
        $t->transaction_date->month === $currentMonth &&
        $t->transaction_date->year === $currentYear
      )->sum(fn($t) => $t->getAmountFloat());

      // 2. Data historis 6 bulan terakhir (per bulan)
      $monthlyTotals = $catTrans
      ->groupBy(fn($t) => $t->transaction_date->format('Y-m'))
      ->map(fn($group) => $group->sum(fn($t) => $t->getAmountFloat()))
      ->values()
      ->toArray();

      // Butuh minimal 3 bulan data untuk analisis
      if (count($monthlyTotals) < 3) continue;

      // 3. Hitung mean dan standar deviasi
      $mean = array_sum($monthlyTotals) / count($monthlyTotals);

      $variance = 0;
      foreach ($monthlyTotals as $value) {
        $variance += pow($value - $mean, 2);
      }
      $variance /= count($monthlyTotals);
      $stdDev = sqrt($variance);

      // Jika standar deviasi 0 (semua nilai sama), tidak bisa hitung Z-score
      if ($stdDev == 0) continue;

      // 4. Hitung Z-score untuk bulan ini
      $zScore = ($thisMonth - $mean) / $stdDev;

      // 5. Deteksi outlier: Z-score > 2.0 (signifikan, di atas 95% confidence)
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

    // Urutkan berdasarkan Z-score tertinggi (paling tidak biasa)
    usort($anomalies, fn($a, $b) => $b['z_score'] <=> $a['z_score']);

    return $anomalies;
  }

  private function detectSubscriptions($transactions): array
  {
    $subscriptions = [];
    $filtered = $transactions->filter(fn($t) => !$t->category->isAdministrative());

    $grouped = $filtered->groupBy(fn($t) =>
      $t->category_id . '|' . $t->description . '|' . $t->getAmountFloat()
    );

    foreach ($grouped as $key => $group) {
      if ($group->count() >= 3) {
        $months = $group->map(fn($t) => $t->transaction_date->format('Y-m'))->unique()->sort()->values();
        $isMonthly = true;
        for ($i = 1; $i < $months->count(); $i++) {
          $diff = Carbon::parse($months[$i])->diffInMonths(Carbon::parse($months[$i-1]));
          if ($diff != 1) {
            $isMonthly = false;
            break;
          }
        }
        if ($isMonthly) {
          $first = $group->first();
          $subscriptions[] = [
            'category' => [
              'id' => $first->category->id,
              'name' => $first->category->name,
              'icon' => $first->category->icon
            ],
            'description' => $first->description,
            'amount' => $first->getAmountFloat(),
            'formatted' => $first->getFormattedAmount(),
            'occurrences' => $group->count(),
            'last_date' => $group->max('transaction_date')->toDateString()
          ];
        }
      }
    }

    return $subscriptions;
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

    // 1. Tren pengeluaran meningkat drastis (>30%)
    if (($trend['change_percentage'] ?? 0) > 30) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-exclamation-triangle',
        'title' => 'Pengeluaran Meningkat Drastis',
        'message' => 'Pengeluaran bulan ini naik ' . $trend['change_percentage'] . '% dibanding bulan lalu. Pertimbangkan untuk meninjau kembali anggaran Anda.',
      ];
    }

    // 2. Lonjakan per kategori
    foreach ($anomalies as $anom) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-graph-up-arrow',
        'title' => 'Lonjakan pada ' . $anom['category']['name'],
        'message' => 'Pengeluaran untuk kategori ini naik ' . $anom['percentage_increase'] . '% dari rata‑rata 3 bulan terakhir. Cek apakah ada pembelian tidak biasa.',
      ];
    }

    // 3. Langganan terdeteksi
    $totalSubs = collect($subscriptions)->sum('amount');
    if ($totalSubs > 0) {
      $recs[] = [
        'type' => 'info',
        'icon' => 'bi-calendar-check',
        'title' => 'Langganan Bulanan Terdeteksi',
        'message' => 'Total pengeluaran langganan Anda sekitar Rp ' . number_format($totalSubs, 0, ',', '.') . ' per bulan. Pertimbangkan untuk mengevaluasi apakah semua langganan masih diperlukan.',
      ];
    }

    // 4. Rasio pengeluaran tersier >40%
    if (($ratio['tertiary'] ?? 0) > 40) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-piggy-bank',
        'title' => 'Kurangi Pengeluaran Tersier',
        'message' => $ratio['tertiary'] . '% pengeluaran Anda digunakan untuk kebutuhan tersier (hiburan, gaya hidup). Menguranginya bisa meningkatkan tabungan.',
      ];
    }

    // 5. Top kategori makanan
    if (!empty($topCategories[0]) && stripos($topCategories[0]['name'], 'makan') !== false) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-cup-hot',
        'title' => 'Hemat Biaya Makan',
        'message' => 'Kategori "' . $topCategories[0]['name'] . '" adalah pengeluaran terbesar Anda. Memasak di rumah atau mencari promo bisa menghemat pengeluaran.',
      ];
    }

    // 6. Budget hampir atau sudah terlampaui
    foreach ($budgets as $budget) {
      if ($budget['is_overspent']) {
        $recs[] = [
          'type' => 'warning',
          'icon' => 'bi-exclamation-octagon',
          'title' => 'Budget Terlampaui: ' . $budget['category']['name'],
          'message' => 'Budget untuk ' . $budget['category']['name'] . ' sudah terlampaui (' . $budget['percentage'] . '%). Segera kurangi pengeluaran di kategori ini.',
        ];
      } elseif ($budget['is_near_limit']) {
        $recs[] = [
          'type' => 'warning',
          'icon' => 'bi-exclamation-triangle',
          'title' => 'Budget Hampir Habis: ' . $budget['category']['name'],
          'message' => 'Budget ' . $budget['category']['name'] . ' sudah mencapai ' . $budget['percentage'] . '%. Hati‑hati dengan sisa hari bulan ini.',
        ];
      }
    }

    // 7. Proyeksi arus kas
    if (($projection['projected_surplus'] ?? 0) < 0) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-graph-down',
        'title' => 'Proyeksi Defisit Bulan Depan',
        'message' => 'Berdasarkan rata‑rata 3 bulan terakhir, pengeluaran Anda diproyeksikan melebihi pemasukan bulan depan. Siapkan dana cadangan.',
      ];
    } elseif (($projection['projected_surplus'] ?? 0) > ($projection['projected_income'] ?? 0) * 0.3) {
      // Surplus besar (>30% pemasukan)
      $recs[] = [
        'type' => 'success',
        'icon' => 'bi-check-circle',
        'title' => 'Ada Ruang untuk Menabung!',
        'message' => 'Anda diproyeksikan surplus Rp ' . number_format($projection['projected_surplus'], 0, ',', '.') . ' bulan depan. Pertimbangkan untuk menambah tabungan atau investasi.',
      ];
    }

    // 8. Belum ada budget
    if (empty($budgets)) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-plus-circle',
        'title' => 'Buat Budget Pertama Anda',
        'message' => 'Anda belum memiliki budget. Buat anggaran untuk mengontrol pengeluaran dan mencapai tujuan keuangan lebih cepat.',
      ];
    }

    return array_slice($recs, 0, 5); // batasi 5 rekomendasi teratas
  }

  /**
  * Proyeksi arus kas untuk N hari ke depan.
  *
  * @return array ['balance' => float, 'avg_daily_expense' => float, 'estimated_needed' => float, 'sufficient' => bool]
  */
  public function getCashflowProjection(int $userId, int $days = 7): array
  {
    $suffix = "cashflow_projection_{$days}";

    return $this->rememberForUser($userId, $suffix, $this->cacheTtl, function () use ($userId, $days) {
      $walletQuery = Wallet::where('user_id', $userId)->where('is_active', true);
      $totalBalance = $walletQuery->sum('balance') / 100; // saldo dalam float

      // 1. Pengeluaran harian dengan EMA (30 hari terakhir)
      $dailyExpenses = Transaction::expense()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
      ->where('transaction_date', '>', Carbon::now()->subDays(30))
      ->orderBy('transaction_date')
      ->get()
      ->groupBy(fn($t) => $t->transaction_date->toDateString())
      ->map(fn($group) => $group->sum(fn($t) => $t->getAmountFloat()));

      $ema = 0;
      $alpha = 0.3; // faktor penghalus
      $lastValue = 0;
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

      // 2. Tren pengeluaran
      $trendFactor = 1.0;
      if ($increasing > $decreasing) {
        $trendFactor = 1.2; // naik 20%
      } elseif ($decreasing > $increasing) {
        $trendFactor = 0.9; // turun 10%
      }

      $avgExpense = $dailyExpenses->average() ?: 0;
      $adjustedExpense = max($ema, $avgExpense) * $trendFactor;

      // 3. Estimasi pemasukan (konservatif)
      $avgIncome = Transaction::income()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
      ->where('transaction_date', '>', Carbon::now()->subDays(30))
      ->sum('amount') / 100 / 30;
      $conservativeIncome = $avgIncome * 0.7;

      // 4. Beban budget
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

      // 5. Langganan (transaksi berulang)
      $subscriptionDaily = $this->getSubscriptionDailyBurden($userId);

      // 6. Proyeksi akhir
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

  /**
  * Beban harian dari transaksi berulang (langganan).
  */
  private function getSubscriptionDailyBurden(int $userId): float
  {
    $analysis = $this->getFullAnalysis($userId);
    $subscriptions = collect($analysis['subscriptions'] ?? []);
    $totalMonthly = $subscriptions->sum('amount');
    return $totalMonthly / 30;
  }

  private function getUserCurrency(int $userId): string
  {
    return Wallet::where('user_id',
      $userId)->value('currency') ?? config('fintech.default_currency',
      'IDR');
  }

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'insights',
      'cashflow_projection_7'
    ];
  }
}