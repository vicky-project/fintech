<?php

namespace Modules\FinTech\Services;

use Nnjeim\World\Models\Currency as WorldCurrency;
use Modules\FinTech\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class CurrencyConverter
{
  /**
  * Konversi nominal dari mata uang asal ke mata uang tujuan.
  */
  public function convert(float $amount, string $fromCurrency, string $toCurrency): float
  {
    if ($amount == 0) return 0;
    if ($fromCurrency === $toCurrency) return $amount;

    // Validasi kode mata uang
    $fromExists = WorldCurrency::where('code', $fromCurrency)->exists();
    $toExists = WorldCurrency::where('code', $toCurrency)->exists();
    if (!$fromExists || !$toExists) {
      throw new \Exception("Mata uang tidak valid.");
    }

    $rate = $this->getExchangeRate($fromCurrency, $toCurrency);
    return round($amount * $rate, 2);
  }

  /**
  * Ambil nilai tukar: database (langsung / reverse) -> API -> fallback.
  */
  public function getExchangeRate(string $from, string $to): float
  {
    // 1. Cek database: pasangan langsung
    $rate = $this->findRate($from, $to);
    if ($rate !== null) return $rate;

    // 2. Cek database: reverse (to -> from)
    $rate = $this->findRate($to, $from);
    if ($rate !== null) return 1 / $rate;

    // 3. Ambil dari API (cache 30 menit)
    $cacheKey = "exchange_rate_{$from}_{$to}";
    return Cache::remember($cacheKey, 1800, function () use ($from, $to) {
      try {
        $response = Http::timeout(5)->get("https://open.er-api.com/v6/latest/{$from}");

        if ($response->successful()) {
          $data = $response->json();
          $rate = $data['rates'][$to] ?? null;

          if ($rate) {
            // Simpan ke database untuk penggunaan berikutnya
            ExchangeRate::updateOrCreate(
              ['from_currency' => $from, 'to_currency' => $to],
              [
                'rate' => (float) $rate,
                'fetched_at' => now(),
              ]
            );

            return (float) $rate;
          }
        }
      } catch (\Exception $e) {
        // Lanjut ke fallback
      }

      // 4. Fallback rate statis
      $fallback = $this->getFallbackRate($from, $to);
      if ($fallback !== null) return $fallback;

      throw new \Exception("Nilai tukar untuk {$from} -> {$to} tidak tersedia.");
    });
  }

  /**
  * Cari rate di database (langsung).
  */
  protected function findRate(string $from,
    string $to): ?float
  {
    $rate = ExchangeRate::where('from_currency',
      $from)
    ->where('to_currency',
      $to)
    ->where('fetched_at',
      '>',
      now()->subHours(12))
    ->orderBy('fetched_at',
      'desc')
    ->first();

    return $rate ? $rate->rate : null;
  }

  /**
  * Dapatkan rate fallback statis.
  */
  protected function getFallbackRate(string $from,
    string $to): ?float
  {
    $fallbackRates = [
      'USD' => ['IDR' => 15800, 'EUR' => 0.92, 'SGD' => 1.34],
      'EUR' => ['IDR' => 17200, 'USD' => 1.09],
      'SGD' => ['IDR' => 11800, 'USD' => 0.75],
      // tambahkan pasangan lain sesuai kebutuhan
    ];

    if (isset($fallbackRates[$from][$to])) {
      \Log::warning("Menggunakan fallback rate untuk {$from}->{$to}");
      return (float) $fallbackRates[$from][$to];
    }

    return null;
  }
}