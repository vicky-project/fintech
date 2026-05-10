<?php

namespace Modules\FinTech\Services;

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
  protected int $cacheTtl = 300; // 5 menit

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
      // Ambil total kekayaan dari semua dompet user
      $wallets = $this->walletService->getUserWallets($user);
      $totalWealth = collect($wallets)->sum('balance');

      // Ambil total pendapatan tahun berjalan (hanya transaksi type 'income')
      // Kita tidak punya method langsung di TransactionService, jadi perlu query atau buat method baru.
      // Untuk efisiensi, kita query langsung (tapi perhatikan hak akses user).
      $yearlyIncome = \Modules\FinTech\Models\Transaction::income()
      ->whereHas('wallet', fn($q) => $q->where('user_id', $user->id))
      ->whereYear('transaction_date', Carbon::now()->year)
      ->sum(\DB::raw('amount / 100'));

      // Ambil harga emas & nisab
      $goldData = $this->getGoldPriceAndNisab();
      $pricePerGram = $goldData['price_per_gram'];
      $nisab = $goldData['nisab'];

      // Hitung zakat mal
      $zakatMal = $this->calculateZakatMal($totalWealth, $nisab);

      // Hitung zakat penghasilan
      $zakatIncome = $this->calculateZakatIncome($yearlyIncome, $nisab);

      // Hitung Pajak Penghasilan (simulasi)
      $incomeTax = $this->calculateIncomeTax($yearlyIncome);

      return [
        'total_wealth' => (float) $totalWealth,
        'yearly_income' => (float) $yearlyIncome,
        'gold_price_per_gram' => $pricePerGram,
        'nisab' => $nisab,
        'zakat_mal' => $zakatMal,
        'zakat_income' => $zakatIncome,
        'income_tax' => $incomeTax,
      ];
    });
  }

  /**
  * Batalkan cache (misalnya setelah data berubah)
  */
  public function clearUserCache(int $userId): void
  {
    $this->clearUserCache($userId);
    Cache::forget('gold_price_nisab_data');
  }

  // -------------------------------------------------------------------------
  // Private helpers
  // -------------------------------------------------------------------------

  private function calculateZakatMal(float $totalWealth, float $nisab): array
  {
    $eligible = $totalWealth >= $nisab;
    $amount = $eligible ? $totalWealth * 0.025 : 0;
    return [
      'eligible' => $eligible,
      'amount' => round($amount, 2),
    ];
  }

  private function calculateZakatIncome(float $yearlyIncome, float $nisab): array
  {
    $eligible = $yearlyIncome >= $nisab;
    $amount = $eligible ? $yearlyIncome * 0.025 : 0;
    return [
      'eligible' => $eligible,
      'amount' => round($amount, 2),
    ];
  }

  /**
  * PPh orang pribadi – tarif progresif Pasal 17, PTKP TK/0 = 54.000.000 (2025)
  */
  private function calculateIncomeTax(float $yearlyIncome): array
  {
    $ptkp = 54000000;
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
    return Cache::remember('gold_price_nisab_data', $this->cacheTtl, function () {
      $pricePerGram = $this->fetchPricePerGram();
      if (!$pricePerGram) {
        throw new \Exception('Gagal mengambil harga emas');
      }
      return [
        'price_per_gram' => $pricePerGram,
        'nisab' => 85 * $pricePerGram,
      ];
    });
  }

  private function fetchPricePerGram(): ?float
  {
    try {
      $response = Http::timeout(10)->get('https://api.genelpara.com/json/',
        [
          'list' => 'altin',
          'sembol' => 'all',
        ]);
      if (!$response->successful()) {
        Log::error('GenelPara API error: ' . $response->status() . ' body: '. $response->json());
        return null;
      }
      $data = $response->json();
      $xauusd = $data['data']['XAUUSD']['satis'] ?? null;
      if (!$xauusd) {
        Log::error('Harga XAUUSD tidak ditemukan');
        return null;
      }
      // XAUUSD = harga per troy ounce (USD)
      $pricePerOunceUSD = (float)$xauusd;
      $pricePerGramUSD = $pricePerOunceUSD / 31.1034768;
      // Konversi ke IDR
      return $this->converter->convert($pricePerGramUSD, 'USD', 'IDR');
    } catch (\Exception $e) {
      Log::error('Gagal fetch harga emas: ' . $e->getMessage());
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