<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class BriPdfParser extends AbstractBankParser
{
  protected string $bankCode = 'bri';

  protected array $skipPatterns = [
    'BANK RAKYAT INDONESIA',
    'Saldo Awal',
    'Saldo Akhir',
    'Total Debit',
    'Total Credit',
    'Halaman',
    'Page',
    'Dicetak',
    'Terbilang',
    'User ID',
    'SEQ',
  ];

  public function canParse(string $filePath, ?string $content = null): bool
  {
    if (!str_ends_with(strtolower($filePath), '.pdf')) return false;
    $text = $content ?? $this->extractText($filePath);
    \Log::debug("Extracted text", ['data' => $text]);

    $briPatterns = [
      'BANK RAKYAT INDONESIA',
      'Melayani Dengan Setulus Hati',
      'BRI',
      'Saldo Awal',
      'Total Debit',
      'Total Credit',
      'Saldo Akhir',
      'Terbilang'
    ];
    $matches = 0;
    foreach ($briPatterns as $pattern) {
      if (stripos($text, $pattern) !== false) $matches++;
    }
    return $matches >= 3;
  }

  public function parse(string $filePath): array
  {
    $text = $this->extractText($filePath);
    $currency = $this->extractCurrency($text);
    \Log::debug("BRI parser.", ['data' => $text]);
    // Ambil hanya bagian setelah "2 dari 2" (atau halaman terakhir)
    $start = strpos($text, '2 dari 2');
    if ($start !== false) {
      $text = substr($text, $start);
    }
    $lines = $this->prepareLines($text);
    \Log::debug("BRI lines", ['lines' => $lines]);
    return $this->extractTransactions($lines);
  }

  private function extractText(string $filePath): string
  {
    $text = parent::extractPdfText($filePath);
    $text = strip_tags($text);
    // Hapus deretan angka "1 1 1..." yang merupakan noise halaman pertama
    $text = preg_replace('/\b(\d\s+){20,}\d+/', '', $text);
    $text = preg_replace('/===== Page \d+ =====/i', '', $text);
    $text = preg_replace('/\d+\s*dari\s*\d+/i', '', $text);
    $text = preg_replace("/\n\s*\n/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    return trim($text);
  }

  private function prepareLines(string $content): array
  {
    $lines = explode("\n", $content);
    return collect($lines)
    ->map(fn($line) => trim($line))
    ->filter(fn($line) => !empty($line))
    ->filter(fn($line) => !$this->shouldSkipLine($line))
    ->values()->all();
  }

  private function shouldSkipLine(string $line): bool
  {
    foreach ($this->skipPatterns as $pattern) {
      if (stripos($line, $pattern) !== false) return true;
    }
    // Hapus baris yang hanya berisi angka sangat panjang (ID/SEQ)
    if (preg_match('/^\d{10,}$/', $line)) return true;
    return false;
  }

  private function extractTransactions(array $lines): array
  {
    $fullText = implode(' ', $lines);
    // Cari semua tanggal sebagai anchor
    preg_match_all('/(\d{2}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})/', $fullText, $dateMatches, PREG_OFFSET_CAPTURE);
    if (empty($dateMatches[0])) return [];

    $transactions = [];
    for ($i = 0; $i < count($dateMatches[0]); $i++) {
      $currentDate = $dateMatches[0][$i][0];
      $currentPos = $dateMatches[0][$i][1] + strlen($currentDate);
      $nextPos = isset($dateMatches[0][$i + 1]) ? $dateMatches[0][$i + 1][1] : strlen($fullText);
      $block = trim(substr($fullText, $currentPos, $nextPos - $currentPos));
      $transaction = $this->parseTransactionBlock($currentDate, $block);
      if ($transaction) $transactions[] = $transaction;
    }
    return array_values(array_filter($transactions, fn($t) => $t['amount'] != 0));
  }

  private function parseTransactionBlock(string $dateStr, string $block): ?array
  {
    $date = Carbon::createFromFormat('d/m/y H:i:s', $dateStr)?->toDateString();
    if (!$date) return null;

    // Cari semua angka dengan dua desimal (format nominal)
    preg_match_all('/([\d,]+\.\d{2})/', $block, $matches, PREG_OFFSET_CAPTURE);
    $numbers = $matches[0];
    if (count($numbers) < 2) return null;

    $debit = $credit = 0;
    $desc = '';

    if (count($numbers) >= 3) {
      // Format standar: debit, kredit, saldo
      $debit = $this->parseAmount($this->cleanNominal($numbers[0][0]));
      $credit = $this->parseAmount($this->cleanNominal($numbers[1][0]));
      $desc = trim(substr($block, 0, $numbers[0][1]));
    } else {
      // Hanya dua nominal: nominal transaksi + saldo
      $nominal = $this->parseAmount($this->cleanNominal($numbers[0][0]));
      // Cek apakah di depan nominal ada "0.00" yang tersembunyi (contoh: "AMI300,000.00")
      $beforeNominal = substr($block, 0, $numbers[0][1]);
      if (preg_match('/(\d+\.\d{2})$/', $beforeNominal, $m)) {
        // Ada nominal lain sebelumnya, kemungkinan kredit
        $credit = $this->parseAmount($this->cleanNominal($m[1]));
        $desc = trim(substr($beforeNominal, 0, -strlen($m[0])));
      } else {
        $debit = $nominal;
        $desc = trim($beforeNominal);
      }
    }

    // Tentukan tipe dan amount
    $type = StatementType::UNKNOWN;
    $amount = 0;
    if ($debit > 0) {
      $type = StatementType::DEBIT;
      $amount = $debit;
    } elseif ($credit > 0) {
      $type = StatementType::CREDIT;
      $amount = $credit;
    }

    // Bersihkan deskripsi dari ID/SEQ di akhir
    $desc = preg_replace('/\s*\d{9,}\s*$/', '', $desc);
    $desc = trim(preg_replace('/\s+/', ' ', $desc));

    return [
      'date' => $date,
      'description' => $desc ?: 'Transaksi BRI',
      'amount' => $amount,
      'type' => $type,
    ];
  }

  private function cleanNominal(string $nominal): string
  {
    // Hapus karakter selain digit, koma, titik
    $nominal = preg_replace('/[^\d,.-]/', '', $nominal);
    // Hapus koma (pemisah ribuan)
    $nominal = str_replace(',', '', $nominal);
    // Jika ada lebih dari satu titik, pertahankan titik terakhir sebagai desimal
    $dots = substr_count($nominal, '.');
    if ($dots > 1) {
      $lastDot = strrpos($nominal, '.');
      $decimal = substr($nominal, $lastDot + 1);
      $whole = substr($nominal, 0, $lastDot);
      $whole = str_replace('.', '', $whole);
      $nominal = $whole . '.' . $decimal;
    }
    return $nominal;
  }

  public function extractCurrency(string $text): string
  {
    $normalized = preg_replace('/\s+/', '', $text);
    if (preg_match('/(?:Currency|MataUang):.*?([A-Z]{3})(?![a-zA-Z])/i', $normalized, $matches)) {
      return strtoupper($matches[1]);
    }
    return $this->currency;
  }
}