<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Enums\MaritalStatus;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\UserSetting;
use Modules\FinTech\Models\Wallet;
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
  protected int $cacheTtl = 300;

  public function __construct(
    WalletService $walletService,
    TransactionService $transactionService,
    CurrencyConverter $converter
  ) {
    $this->walletService = $walletService;
    $this->transactionService = $transactionService;
    $this->converter = $converter;
  }

  public function getDashboardData($user, int $year): array
  {
    return $this->rememberForUser($user->id, "zakat_tax_dashboard_{$year}", $this->cacheTtl, function () use ($user, $year) {
      $userSettings = UserSetting::where('user_id', $user->id)->first();
      if (!$userSettings) {
        throw new \Exception("User setting not found.");
      }

      $defaultCurrency = $userSettings->default_currency ?? 'IDR';

      // Total kekayaan: konversi setiap wallet balance
      $wallets = $this->walletService->getUserWallets($user);
      $totalWealth = 0;
      foreach ($wallets as $wallet) {
        $balance = $wallet['balance'];
        $currency = $wallet['currency']['code'];
        if ($currency === $defaultCurrency) {
          $totalWealth += $balance;
        } else {
          try {
            $totalWealth += $this->converter->convert($balance, $currency, $defaultCurrency);
          } catch (\Exception $e) {
            Log::warning("Konversi wallet gagal: " . $e->getMessage());
            $totalWealth += $balance;
          }
        }
      }

      // Pendapatan tahunan: agregasi per wallet di database
      $yearlyIncome = $this->getYearlyIncome($user, $year, $defaultCurrency);

      $maritalStatus = $userSettings->marital_status ?? MaritalStatus::SINGLE;
      $dependents = $userSettings->dependents ?? 0;

      $goldData = $this->getGoldPriceAndNisab();
      $pricePerGram = $goldData['price_per_gram'];
      $nisab = $goldData['nisab'];

      $zakatMal = $this->calculateZakatMal($totalWealth, $nisab);
      $zakatIncome = $this->calculateZakatIncome($yearlyIncome, $nisab);
      $incomeTax = $this->calculateIncomeTax($yearlyIncome, $maritalStatus, $dependents);

      return [
        'total_wealth' => (float) $totalWealth,
        'yearly_income' => (float) $yearlyIncome,
        'gold_price_per_gram' => $pricePerGram,
        'nisab' => $nisab,
        'marital_status' => $maritalStatus->value,
        'dependents' => $dependents,
        'zakat_mal' => $zakatMal,
        'zakat_income' => $zakatIncome,
        'income_tax' => $incomeTax,
        'historical_tax' => $this->getHistoricalTaxData($user),
        'year' => $year,
      ];
    });
  }

  /**
  * Hitung pendapatan tahunan dengan agregasi per wallet dan konversi.
  */
  private function getYearlyIncome($user,
    int $year,
    string $defaultCurrency): float
  {
    $walletTotals = Transaction::income()
    ->whereHas('wallet',
      fn($q) => $q->where('user_id', $user->id))
    ->whereHas('category',
      fn($q) => $q->whereJsonDoesntContain('metadata->tags', 'exclude_from_income'))
    ->whereYear('transaction_date',
      $year)
    ->selectRaw('wallet_id, SUM(amount/100) as total')
    ->groupBy('wallet_id')
    ->with('wallet')
    ->get();

    $total = 0;
    foreach ($walletTotals as $item) {
      $wallet = $item->wallet;
      $currency = $wallet->currency;
      $amount = (float) $item->total;
      if ($currency === $defaultCurrency) {
        $total += $amount;
      } else {
        try {
          $total += $this->converter->convert($amount, $currency, $defaultCurrency);
        } catch (\Exception $e) {
          Log::warning("Konversi transaksi wallet {$wallet->id} gagal: " . $e->getMessage());
          $total += $amount;
        }
      }
    }
    return $total;
  }

  /**
  * Data historis pajak per tahun – agregasi per tahun & per wallet di database.
  */
  public function getHistoricalTaxData($user): array
  {
    $userSettings = UserSetting::where('user_id', $user->id)->first();
    $defaultCurrency = $userSettings->default_currency ?? 'IDR';
    $maritalStatus = $userSettings->marital_status ?? MaritalStatus::SINGLE;
    $dependents = $userSettings->dependents ?? 0;

    // Agregasi per tahun dan per wallet
    $yearlyWalletTotals = Transaction::income()
    ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
    ->whereHas('category', fn($q) => $q->whereJsonDoesntContain('metadata->tags', 'exclude_from_income'))
    ->selectRaw('YEAR(transaction_date) as year, wallet_id, SUM(amount/100) as total')
    ->groupBy('year', 'wallet_id')
    ->with('wallet')
    ->get();

    // Kelompokkan per tahun, konversi setiap wallet
    $yearlyTotals = [];
    foreach ($yearlyWalletTotals as $item) {
      $year = $item->year;
      $wallet = $item->wallet;
      $currency = $wallet->currency;
      $amount = (float) $item->total;
      if (!isset($yearlyTotals[$year])) {
        $yearlyTotals[$year] = 0;
      }
      if ($currency === $defaultCurrency) {
        $yearlyTotals[$year] += $amount;
      } else {
        try {
          $yearlyTotals[$year] += $this->converter->convert($amount, $currency, $defaultCurrency);
        } catch (\Exception $e) {
          Log::warning("Konversi historis wallet {$wallet->id} tahun {$year} gagal: " . $e->getMessage());
          $yearlyTotals[$year] += $amount;
        }
      }
    }

    // Hitung pajak per tahun
    $historical = [];
    foreach ($yearlyTotals as $year => $totalIncome) {
      $jobExpense = min($totalIncome * 0.05, 6000000);
      $netIncome = max(0, $totalIncome - $jobExpense);
      $ptkp = $this->getPTKP($maritalStatus, $dependents);
      $pkp = max(0, $netIncome - $ptkp);
      $tax = $this->calculateTaxByPkp($pkp);
      $historical[] = [
        'year' => $year,
        'income' => round($totalIncome, 2),
        'job_expense' => $jobExpense,
        'net_income' => round($netIncome, 2),
        'ptkp' => $ptkp,
        'pkp' => round($pkp, 2),
        'tax' => round($tax, 2),
      ];
    }

    // Urutkan dari tahun terbaru
    usort($historical, fn($a, $b) => $b['year'] <=> $a['year']);
    return $historical;
  }

  // -------------------------------------------------------------------------
  // Zakat helpers
  // -------------------------------------------------------------------------
  private function calculateZakatMal(float $totalWealth, float $nisab): array
  {
    $eligible = $totalWealth >= $nisab;
    $amount = $eligible ? $totalWealth * 0.025 : 0;
    return ['eligible' => $eligible,
      'amount' => round($amount, 2)];
  }

  private function calculateZakatIncome(float $yearlyIncome, float $nisab): array
  {
    $eligible = $yearlyIncome >= $nisab;
    $amount = $eligible ? $yearlyIncome * 0.025 : 0;
    return ['eligible' => $eligible,
      'amount' => round($amount, 2)];
  }

  // -------------------------------------------------------------------------
  // PTKP & PPh
  // -------------------------------------------------------------------------
  private function getPTKP(MaritalStatus $maritalStatus, int $dependents): float
  {
    $base = 54000000; // TK/0
    if ($maritalStatus === MaritalStatus::MARRIED) {
      $base = 58500000; // K/0
    }
    $additional = min($dependents, 3) * 4500000;
    return $base + $additional;
  }

  private function calculateIncomeTax(float $yearlyIncome, MaritalStatus $maritalStatus, int $dependents): array
  {
    $jobExpense = min($yearlyIncome * 0.05, 6000000);
    $netIncome = max(0, $yearlyIncome - $jobExpense);
    $ptkp = $this->getPTKP($maritalStatus, $dependents);
    $pkp = max(0, $netIncome - $ptkp);
    $tax = $this->calculateTaxByPkp($pkp);
    return [
      'ptkp' => (float) $ptkp,
      'pkp' => round($pkp, 2),
      'tax' => round($tax, 2),
      'job_expense' => $jobExpense,
      'net_income' => $netIncome,
    ];
  }

  private function calculateTaxByPkp(float $pkp): float
  {
    $remaining = $pkp;
    if ($remaining <= 60000000) {
      return $remaining * 0.05;
    }
    $tax = 60000000 * 0.05;
    $remaining -= 60000000;

    $layer2 = min($remaining, 190000000);
    $tax += $layer2 * 0.15;
    $remaining -= $layer2;
    if ($remaining <= 0) return $tax;

    $layer3 = min($remaining, 250000000);
    $tax += $layer3 * 0.25;
    $remaining -= $layer3;
    if ($remaining <= 0) return $tax;

    $layer4 = min($remaining, 4500000000);
    $tax += $layer4 * 0.30;
    $remaining -= $layer4;
    if ($remaining <= 0) return $tax;

    $tax += $remaining * 0.35;
    return $tax;
  }

  // -------------------------------------------------------------------------
  // Harga Emas & Nisab
  // -------------------------------------------------------------------------
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
        Log::error('Apised response missing fields');
        return null;
      }
      $priceInBaseCurrency = (float) $metalPrices['price'];
      $rateToIdr = (float) ($currencyRates['IDR'] ?? 0);
      if ($rateToIdr <= 0) {
        Log::error('Currency rate IDR tidak ditemukan');
        return null;
      }
      return round($priceInBaseCurrency * $rateToIdr, 2);
    } catch (\Exception $e) {
      Log::error('Gagal fetch harga emas: ' . $e->getMessage());
      return null;
    }
  }

  protected function knownUserCacheSuffixes(int $userId): array
  {
    return ['zakat_tax_dashboard'];
  }
}