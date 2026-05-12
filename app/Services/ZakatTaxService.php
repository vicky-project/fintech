<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Enums\MaritalStatus;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Services\WalletService;
use Modules\FinTech\Services\TransactionService;
use Modules\FinTech\Services\CurrencyConverter;
use Modules\FinTech\Traits\HasUserCache;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZakatTaxService
{
  use HasUserCache;

  protected WalletService $walletService;
  protected TransactionService $transactionService;
  protected CurrencyConverter $converter;
  protected int $cacheTtl = 300; // 5 menit, bisa ubah jadi 3600 untuk 1 jam

  public function __construct(
    WalletService $walletService,
    TransactionService $transactionService,
    CurrencyConverter $converter
  ) {
    $this->walletService = $walletService;
    $this->transactionService = $transactionService;
    $this->converter = $converter;
  }

  /**
  * Get dashboard data for Zakat & Pajak (per user)
  */
  public function getDashboardData($user, int $year): array
  {
    return $this->rememberForUser($user->id, "zakat_tax_dashboard_{$year}", $this->cacheTtl, function () use ($user, $year) {
      $userSettings = UserSetting::where('user_id', $user->id)->first();
      if (!$userSettings) {
        throw new \Exception("User not found.");
      }

      // Ambil total kekayaan dari semua dompet user
      $wallets = $this->walletService->getUserWallets($user);
      $totalWealth = collect($wallets)->sum('balance');

      // Ambil total pendapatan tahun berjalan (hanya transaksi type 'income')
      $yearlyIncome = Transaction::income()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->whereHas('category', function($q) {
        $q->whereJsonDoesntContain('metadata->tags', 'exclude_from_income');
      })
      ->whereYear('transaction_date', $year)
      ->sum(\DB::raw('amount / 100'));

      // Ambil data marital_status dan dependents dari user setting
      $maritalStatus = $userSettings->marital_status ?? MaritalStatus::SINGLE;
      $dependents = $userSettings->dependents ?? 0;

      // Ambil harga emas & nisab dari API Apised
      $goldData = $this->getGoldPriceAndNisab();
      $pricePerGram = $goldData['price_per_gram'];
      $nisab = $goldData['nisab'];

      // Hitung zakat mal
      $zakatMal = $this->calculateZakatMal($totalWealth, $nisab);
      $zakatIncome = $this->calculateZakatIncome($yearlyIncome, $nisab);
      $incomeTax = $this->calculateIncomeTax($yearlyIncome, $maritalStatus, $dependents);

      return [
        'total_wealth' => (float) $totalWealth,
        'yearly_income' => (float) $yearlyIncome,
        'gold_price_per_gram' => $pricePerGram,
        'nisab' => $nisab,
        'marital_status' => $maritalStatus,
        // opsional untuk frontend
        'dependents' => $dependents,
        // opsional untuk frontend
        'zakat_mal' => $zakatMal,
        'zakat_income' => $zakatIncome,
        'income_tax' => $incomeTax,
        'historical_tax' => $this->getHistoricalTaxData($user),
        'year' => $year
      ];
    });
  }

  // -------------------------------------------------------------------------
  // Private helpers
  // -------------------------------------------------------------------------

  private function calculateZakatMal(float $totalWealth,
    float $nisab): array
  {
    $eligible = $totalWealth >= $nisab;
    $amount = $eligible ? $totalWealth * 0.025 : 0;
    return [
      'eligible' => $eligible,
      'amount' => round($amount,
        2),
    ];
  }

  private function calculateZakatIncome(float $yearlyIncome,
    float $nisab): array
  {
    $eligible = $yearlyIncome >= $nisab;
    $amount = $eligible ? $yearlyIncome * 0.025 : 0;
    return [
      'eligible' => $eligible,
      'amount' => round($amount,
        2),
    ];
  }

  /**
  * Hitung PTKP berdasarkan status perkawinan dan jumlah tanggungan (anak)
  * Aturan PTKP 2025 (berlaku untuk tahun pajak 2025):
  * - TK/0 : Rp 54.000.000
  * - K/0  : Rp 58.500.000 (Kawin, tanpa tanggungan)
  * - K/1  : Rp 63.000.000 (Kawin + 1 tanggungan)
  * - K/2  : Rp 67.500.000 (Kawin + 2 tanggungan)
  * - K/3  : Rp 72.000.000 (Kawin + 3 tanggungan)
  * Untuk status cerai/janda/duda, perhitungan sama dengan TK/0.
  */
  private function getPTKP(MaritalStatus $maritalStatus,
    int $dependents): float
  {
    $base = 54000000; // TK/0

    if ($maritalStatus === MaritalStatus::MARRIED) {
      $base = 58500000; // K/0
    }

    // Tanggungan maksimal 3 orang (anak)
    $additional = min($dependents, 3) * 4500000;
    return $base + $additional;
  }

  /**
  * PPh orang pribadi – tarif progresif Pasal 17
  * Menggunakan PTKP dinamis berdasarkan status dan tanggungan.
  */
  private function calculateIncomeTax(float $yearlyIncome, MaritalStatus $maritalStatus, int $dependents): array
  {
    $ptkp = $this->getPTKP($maritalStatus, $dependents);
    $pkp = max(0, $yearlyIncome - $ptkp);
    $tax = 0;

    if ($pkp > 0) {
      if ($pkp <= 60000000) {
        $tax = $pkp * 0.05;
      } elseif ($pkp <= 250000000) {
        $tax = 60000000 * 0.05 + ($pkp - 60000000) * 0.15;
      } elseif ($pkp <= 500000000) {
        $tax = 60000000 * 0.05 + 190000000 * 0.15 + ($pkp - 250000000) * 0.25;
      } else {
        $tax = 60000000 * 0.05 + 190000000 * 0.15 + 250000000 * 0.25 + ($pkp - 500000000) * 0.30;
      }
    }

    return [
      'ptkp' => (float) $ptkp,
      'pkp' => round($pkp, 2),
      'tax' => round($tax, 2),
    ];
  }

  /**
  * Hitung PPh dengan tarif progresif sesuai Pasal 17 UU HPP.
  * (Lapisan: 5% - 15% - 25% - 30% - 35%)
  */
  private function calculateTaxByPkp(float $pkp): float
  {
    $tax = 0;
    $remaining = $pkp;

    // Layer 1: sampai dengan Rp60.000.000
    if ($remaining <= 60000000) {
      $tax = $remaining * 0.05;
      return $tax;
    }
    $tax = 60000000 * 0.05;
    $remaining -= 60000000;

    // Layer 2: Rp60.000.001 sampai Rp250.000.000
    $layer2 = min($remaining, 190000000); // 250jt - 60jt = 190jt
    $tax += $layer2 * 0.15;
    $remaining -= $layer2;

    if ($remaining <= 0) return $tax;

    // Layer 3: Rp250.000.001 sampai Rp500.000.000
    $layer3 = min($remaining, 250000000); // 500jt - 250jt = 250jt
    $tax += $layer3 * 0.25;
    $remaining -= $layer3;

    if ($remaining <= 0) return $tax;

    // Layer 4: Rp500.000.001 sampai Rp5.000.000.000
    $layer4 = min($remaining, 4500000000); // 5M - 500jt = 4.5M
    $tax += $layer4 * 0.30;
    $remaining -= $layer4;

    // Layer 5: di atas Rp5.000.000.000
    if ($remaining > 0) {
      $tax += $remaining * 0.35;
    }

    return $tax;
  }

  /**
  * Dapatkan data historis pajak per tahun (berdasarkan transaksi income)
  */
  public function getHistoricalTaxData($user): array
  {
    $excludedCategoryIds = Category::whereJsonContains('metadata->tags', "exclude_from_income")
    ->pluck('id');

    $yearlyIncomes = Transaction::income()
    ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->whereNotIn('category_id', $excludedCategoryIds)
    ->selectRaw('YEAR(transaction_date) as year, SUM(amount/100) as total')
    ->groupBy('year')
    ->orderBy('year', 'desc')
    ->get()
    ->keyBy('year')
    ->map(fn($item) => (float) $item->total);

    $userSettings = UserSetting::where('user_id', $user->id)->first();
    $maritalStatus = $userSetting->marital_status ?? MaritalStatus::SINGLE;
    $dependents = $userSetting->dependents ?? 0;
    $ptkp = $this->getPTKP($maritalStatus, $dependents);

    $historical = [];
    foreach ($yearlyIncomes as $year => $income) {
      $pkp = max(0, $income - $ptkp);
      $tax = $this->calculateTaxByPkp($pkp);
      $historical[] = [
        'year' => $year,
        'income' => round($income, 2),
        'ptkp' => $ptkp,
        'pkp' => round($pkp, 2),
        'tax' => round($tax, 2),
      ];
    }

    return $historical;
  }

  private function getGoldPriceAndNisab(): array
  {
    return Cache::remember('gold_price_nisab_data', 3600, function () {
      $pricePerGram = $this->fetchPricePerGramFromApised();
      if (!$pricePerGram) {
        throw new \Exception('Gagal mengambil harga emas dari API Apised');
      }
      return [
        'price_per_gram' => $pricePerGram,
        'nisab' => 85 * $pricePerGram,
      ];
    });
  }

  /**
  * Mengambil harga emas per gram dalam IDR dari Apised API.
  * Endpoint: /v1/latest?metals=XAU&base_currency=USD&currencies=IDR&weight_unit=gram
  * Batas limit 100 request per bulan – diatasi dengan cache 5 menit.
  */
  private function fetchPricePerGramFromApised(): ?float
  {
    $apiKey = config('fintech.apised.api_key',
      env('APISED_API_KEY'));
    $baseUrl = config('fintech.apised.base_url',
      'https://gold.g.apised.com');

    if (!$apiKey) {
      Log::error('APISED_API_KEY tidak dikonfigurasi');
      return null;
    }

    try {
      $response = Http::timeout(10)
      ->withHeaders(['x-api-key' => $apiKey])
      ->get($baseUrl . '/v1/latest', [
        'metals' => 'XAU',
        'base_currency' => 'USD',
        'currencies' => 'IDR',
        'weight_unit' => 'gram',
      ]);

      if (!$response->successful()) {
        Log::error('Apised API error: ' . $response->status() . ' body: ' . $response->body());
        return null;
      }

      $data = $response->json();
      $metalPrices = $data['data']['metal_prices']['XAU'] ?? null;
      $currencyRates = $data['data']['currency_rates'] ?? null;

      if (!$metalPrices || !$currencyRates) {
        Log::error('Apised response missing required fields');
        return null;
      }

      $priceInBaseCurrency = (float) $metalPrices['price'];
      $rateToIdr = (float) ($currencyRates['IDR'] ?? 0);

      if ($rateToIdr <= 0) {
        Log::error('Currency rate IDR tidak ditemukan');
        return null;
      }

      $priceInIdr = $priceInBaseCurrency * $rateToIdr;
      return round($priceInIdr, 2);
    } catch (\Exception $e) {
      Log::error('Gagal fetch harga emas dari Apised: ' . $e->getMessage());
      return null;
    }
  }

  // Override agar suffix yang diketahui bisa di-clear jika perlu
  protected function knownUserCacheSuffixes(int $userId): array
  {
    return [
      'zakat_tax_dashboard',
    ];
  }
}