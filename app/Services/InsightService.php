<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Wallet;
use Modules\FinTech\Enums\TransactionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class InsightService
{
  /**
  * Mendapatkan analisis lengkap untuk user dengan caching.
  */
  public function getFullAnalysis(int $userId): array
  {
    $cacheKey = "fintech_insights_user_{$userId}";
    $ttl = 3600; // 1 jam

    if ($this->supportsTags()) {
      return Cache::tags(['fintech_insights', "user_{$userId}"])
      ->remember($cacheKey, $ttl, fn() => $this->computeAnalysis($userId));
    }

    return Cache::remember($cacheKey, $ttl, fn() => $this->computeAnalysis($userId));
  }

  /**
  * Komputasi analisis (query intensif).
  */
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

    // 7. Hasilkan Rekomendasi Cerdas
    $recommendations = $this->generateSmartRecommendations(
      $trend,
      $topCategories,
      $anomalies,
      $subscriptions,
      $spendingRatio
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
      ]
    ];
  }

  /**
  * Hapus cache insight untuk user tertentu.
  */
  public static function clearCache(int $userId): void
  {
    if ((new self)->supportsTags()) {
      Cache::tags(['fintech_insights', "user_{$userId}"])->flush();
    } else {
      Cache::forget("fintech_insights_user_{$userId}");
    }
  }

  /**
  * Cek apakah cache driver mendukung tags.
  */
  private function supportsTags(): bool
  {
    return Cache::getStore() instanceof \Illuminate\Cache\TaggableStore;
  }

  /**
  * Hitung total pengeluaran per bulan selama 6 bulan terakhir.
  */
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

  /**
  * Ambil top kategori pengeluaran bulan ini.
  */
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

  /**
  * Deteksi lonjakan pengeluaran per kategori (>50% dari rata-rata 3 bulan).
  */
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

      $past3Months = $catTrans->filter(fn($t) =>
        $t->transaction_date->lt(Carbon::now()->startOfMonth())
      );

      if ($past3Months->count() < 2) continue;

      $avgPast = $past3Months->groupBy(fn($t) => $t->transaction_date->format('Y-m'))
      ->map->sum(fn($t) => $t->getAmountFloat())
      ->average();

      if ($avgPast > 0 && $thisMonth > $avgPast * 1.5) {
        $anomalies[] = [
          'category' => [
            'id' => $category->id,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color
          ],
          'this_month' => round($thisMonth, 2),
          'average' => round($avgPast, 2),
          'percentage_increase' => round(($thisMonth - $avgPast) / $avgPast * 100, 1),
          'formatted' => 'Rp ' . number_format($thisMonth, 0, ',', '.')
        ];
      }
    }

    return array_values($anomalies);
  }

  /**
  * Deteksi transaksi berulang (langganan) - nominal sama setiap bulan.
  */
  private function detectSubscriptions($transactions): array
  {
    $subscriptions = [];
    $grouped = $transactions->groupBy(fn($t) =>
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

  /**
  * Hitung rasio pengeluaran berdasarkan tags (pokok/sekunder/tersier).
  */
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

  /**
  * Proyeksi arus kas bulan depan berdasarkan rata-rata 3 bulan terakhir.
  */
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

  /**
  * Hasilkan rekomendasi cerdas.
  */
  private function generateSmartRecommendations($trend, $topCategories, $anomalies, $subscriptions, $ratio): array
  {
    $recs = [];

    if ($trend['change_percentage'] > 30) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-exclamation-triangle',
        'title' => 'Pengeluaran Meningkat Drastis',
        'message' => 'Pengeluaran bulan ini naik ' . $trend['change_percentage'] . '% dibanding bulan lalu.'
      ];
    }

    foreach ($anomalies as $anom) {
      $recs[] = [
        'type' => 'warning',
        'icon' => 'bi-graph-up-arrow',
        'title' => 'Lonjakan pada ' . $anom['category']['name'],
        'message' => 'Naik ' . $anom['percentage_increase'] . '% dari biasanya.'
      ];
    }

    $totalSubs = collect($subscriptions)->sum('amount');
    if ($totalSubs > 0) {
      $recs[] = [
        'type' => 'info',
        'icon' => 'bi-calendar-check',
        'title' => 'Langganan Bulanan Terdeteksi',
        'message' => 'Total Rp ' . number_format($totalSubs, 0, ',', '.') . ' per bulan.'
      ];
    }

    if ($ratio['tertiary'] > 40) {
      $recs[] = [
        'type' => 'tip',
        'icon' => 'bi-piggy-bank',
        'title' => 'Kurangi Pengeluaran Tersier',
        'message' => $ratio['tertiary'] . '% untuk kebutuhan tersier.'
      ];
    }

    if (!empty($topCategories)) {
      $top = $topCategories[0];
      if (stripos($top['name'], 'makan') !== false) {
        $recs[] = [
          'type' => 'tip',
          'icon' => 'bi-cup-hot',
          'title' => 'Hemat Biaya Makan',
          'message' => 'Pengeluaran terbesar Anda.'
        ];
      }
    }

    return array_slice($recs, 0, 5);
  }

  /**
  * Mendapatkan kode mata uang user.
  */
  private function getUserCurrency(int $userId): string
  {
    return Wallet::where('user_id', $userId)->value('currency') ?? config('fintech.default_currency', 'IDR');
  }
}