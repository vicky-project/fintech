<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class BriPdfParser extends AbstractBankParser
{
  protected string $bankCode = 'bri';

  /**
  * Pola teks yang umum ditemukan di e-statement BRI.
  */
  protected array $briPatterns = [
    'BANK RAKYAT INDONESIA',
    'BRI',
    'Rekening Koran',
    'Account Statement',
    'Periode',
    'Cabang',
    'No. Rekening',
    'Mata Uang',
    'Saldo Awal',
    'Saldo Akhir',
  ];

  /**
  * Pola baris yang harus diabaikan (header, footer, informasi umum).
  */
  protected array $skipPatterns = [
    'BANK RAKYAT INDONESIA',
    'BRI',
    'Rekening Koran',
    'Account Statement',
    'Periode',
    'Cabang',
    'No. Rekening',
    'Nama Nasabah',
    'Mata Uang',
    'Saldo Awal',
    'Saldo Akhir',
    'Total Debet',
    'Total Kredit',
    'Halaman',
    'Page',
    'Printed',
    'Dicetak',
  ];

  public function canParse(string $filePath, ?string $content = null): bool
  {
    if (!str_ends_with(strtolower($filePath), '.pdf')) {
      return false;
    }

    $text = $content ?? $this->extractText($filePath);

    // Hitung berapa banyak pola khas BRI yang muncul
    $matches = 0;
    foreach ($this->briPatterns as $pattern) {
      if (stripos($text, $pattern) !== false) {
        $matches++;
      }
    }

    // Minimal 3 pola harus cocok untuk memastikan ini file BRI
    return $matches >= 3;
  }

  public function parse(string $filePath): array
  {
    $text = $this->extractText($filePath);
    \Log::debug("Bri parser.", ['data' => $text]);
    $currency = $this->extractCurrency($text);

    $lines = $this->prepareLines($text);

    \Log::debug("BRI lines", ['lines' => $lines]);

    // TODO: Implementasikan logika ekstraksi transaksi setelah
    // melihat struktur sebenarnya dari file PDF BRI.
    // Untuk sementara, kembalikan array kosong.
    return [];
  }

  /**
  * Mendapatkan mata uang dari teks PDF.
  */
  public function extractCurrency(string $text): string
  {
    // Hapus semua whitespace untuk memudahkan pencarian
    $normalized = preg_replace('/\s+/', '', $text);

    // Cari "Currency:" atau "MataUang:" lalu tiga huruf besar yang berdiri sendiri
    if (preg_match('/(?:Currency|MataUang):.*?([A-Z]{3})(?![a-zA-Z])/i', $normalized, $matches)) {
      return strtoupper($matches[1]);
    }

    return $this->currency; // fallback ke 'IDR'
  }

  /**
  * Ekstrak teks dari PDF.
  */
  private function extractText(string $filePath): string
  {
    $text = parent::extractPdfText($filePath);
    $text = preg_replace("/\n\s*\n/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    return $text;
  }

  /**
  * Bersihkan baris dari teks yang tidak relevan.
  */
  private function prepareLines(string $content): array
  {
    $lines = explode("\n", $content);

    return collect($lines)
    ->map(fn($line) => trim($line))
    ->filter(fn($line) => !empty($line))
    ->filter(fn($line) => !$this->shouldSkipLine($line))
    ->values()
    ->all();
  }

  /**
  * Cek apakah baris harus dilewati.
  */
  private function shouldSkipLine(string $line): bool
  {
    foreach ($this->skipPatterns as $pattern) {
      if (stripos($line, $pattern) !== false) {
        return true;
      }
    }
    // Skip nomor halaman
    if (preg_match('/^\d+\s*dari\s*\d+/i', $line)) {
      return true;
    }
    if (preg_match('/^Halaman\s+\d+/i', $line)) {
      return true;
    }
    return false;
  }
}