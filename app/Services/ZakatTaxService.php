<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Enums\MaritalStatus;
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
  public function getDashboardData($user): array
  {
    return $this->rememberForUser($user->id, 'zakat_tax_dashboard', $this->cacheTtl, function () use ($user) {
      $userSettings = UserSetting::where('user_id', $user->id)->first();
      if (!$userSettings) {
        throw new \Exception("User not found.");
      }

      // Ambil total kekayaan dari semua dompet user
      $wallets = $this->walletService->getUserWallets($user);
      $totalWealth = collect($wallets)->sum('balance');

      // Ambil total pendapatan tahun berjalan (hanya transaksi type 'income')
      $yearlyIncome = \Modules\FinTech\Models\Transaction::income()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->whereYear('transaction_date', Carbon::now()->year)
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
  private function calculateIncomeTax(float $yearlyIncome, string $maritalStatus, int $dependents): array
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