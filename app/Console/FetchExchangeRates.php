<?php

namespace Modules\FinTech\Console;

use Illuminate\Console\Command;
use Modules\FinTech\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;

class FetchExchangeRates extends Command
{
  protected $signature = 'app:exchange-rates';
  protected $description = 'Ambil kurs lengkap dari open.er-api.com untuk beberapa base currency';

  /**
  * Daftar base currency yang akan diambil.
  * Semakin banyak, semakin lengkap pasangan yang tersimpan.
  */
  protected array $baseCurrencies = [
    'USD',
    'EUR',
    'IDR',
    'SGD',
    'JPY',
    'GBP',
    'AUD'
  ];

  public function handle(): void
  {
    $this->info('Mengambil kurs terbaru...');

    foreach ($this->baseCurrencies as $base) {
      try {
        $response = Http::timeout(15)->get("https://open.er-api.com/v6/latest/{$base}");

        if ($response->failed()) {
          $this->error("Gagal mengambil data untuk {$base}: HTTP " . $response->status());
          continue;
        }

        $data = $response->json();
        $rates = $data['rates'] ?? [];

        if (empty($rates)) {
          $this->warn("Data kosong untuk {$base}");
          continue;
        }

        $count = 0;
        foreach ($rates as $to => $rate) {
          if ($to === $base) continue;

          ExchangeRate::updateOrCreate(
            ['from_currency' => $base, 'to_currency' => $to],
            [
              'rate' => (float) $rate,
              'fetched_at' => now(),
            ]
          );
          $count++;
        }

        $this->info("{$base}: {$count} pasangan tersimpan.");
        sleep(1); // hormati rate limit API
      } catch (\Exception $e) {
        $this->error("Error untuk {$base}: " . $e->getMessage());
      }
    }

    $this->info('Selesai.');
  }
}