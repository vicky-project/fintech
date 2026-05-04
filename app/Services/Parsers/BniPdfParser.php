<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class BniPdfParser extends AbstractBankParser
{
  protected string $bankCode = 'bni';

  /**
  * Pola teks yang umum ditemukan di e-statement BNI.
  */
  protected array $bniPatterns = [
    'HISTORI TRANSAKSI',
    'TAPLUS MUDA DIGITAL',
    'BNI',
    'BANK NEGARA INDONESIA',
    'Kriteria Pencarian',
  ];

  /**
  * Pola baris yang harus diabaikan (header, footer, informasi umum).
  */
  protected array $skipPatterns = [
    'HISTORI TRANSAKSI',
    'Kriteria Pencarian',
    'TAPLUS MUDA DIGITAL',
    'Transactions List',
    'Tanggal Transaksi',
    'Uraian Transaksi',
    'Saldo Akhir',
    'Printed on',
    'Page',
    'Halaman',
    '=====',
  ];

  public function canParse(string $filePath, ?string $content = null): bool
  {
    if (!str_ends_with(strtolower($filePath), '.pdf')) {
      return false;
    }

    $text = $content ?? $this->extractText($filePath);

    // Hitung berapa banyak pola khas BNI yang muncul
    $matches = 0;
    foreach ($this->bniPatterns as $pattern) {
      if (stripos($text, $pattern) !== false) {
        $matches++;
      }
    }

    return $matches >= 2; // Minimal 2 pola cocok
  }

  public function parse(string $filePath): array
  {
    $text = $this->extractText($filePath);
    $currency = $this->extractCurrency($text);
    $transactions = $this->extractTransactions($text);
    return $this->formatTransactions($transactions);
  }

  /**
  * Ekstrak teks dari PDF dan normalisasi.
  */
  private function extractText(string $filePath): string
  {
    $text = parent::extractPdfText($filePath);

    // Hapus tag HTML yang mungkin terbawa dari ekstraksi PDF
    $text = strip_tags($text);

    // Normalisasi spasi dan newline
    $text = preg_replace("/\n\s*\n/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    $text = preg_replace('/\bPage \d+ of \d+\b/i', '', $text);
    $text = preg_replace('/===== Page \d+ =====/i', '', $text);

    return trim($text);
  }

  private function extractCurrency(string $text): string
  {
    if (preg_match('/\(([A-Z]{3})\)/', $text, $matches)) {
      return strtoupper($matches[1]);
    }
    return $this->currency;
  }

  /**
  * Ekstrak transaksi dari teks mentah.
  */
  private function extractTransactions(string $text): array
  {
    $transactions = [];

    // Pisahkan teks menjadi blok per transaksi berdasarkan tanggal
    // Format tanggal: YYYY-MM-DD
    $pattern = '/(\d{4}-\d{2}-\d{2})\s+(.*?)(?=\d{4}-\d{2}-\d{2}|Printed on|Page \d+ of \d+|$)/s';

    if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        $dateStr = $match[1];
        $content = $match[2];

        $transaction = $this->parseTransactionBlock($dateStr, $content);
        if ($transaction) {
          $transactions[] = $transaction;
        }
      }
    }

    return $transactions;
  }

  /**
  * Parse satu blok transaksi (tanggal + konten).
  */
  private function parseTransactionBlock(string $dateStr, string $content): ?array
  {
    // Normalisasi tanggal
    $date = Carbon::createFromFormat('Y-m-d', $dateStr)?->toDateString();
    if (!$date) return null;

    // Cari nominal dan tipe transaksi (Db. atau Cr.)
    $amount = 0;
    $type = StatementType::UNKNOWN;

    // Pola: Db. atau Cr. diikuti nominal
    if (preg_match('/(Db\.|Cr\.)\s*([\d\.]+,\d{2})/', $content, $amountMatch)) {
      $typeStr = $amountMatch[1];
      $amountStr = $amountMatch[2];
      $amount = $this->parseAmount($amountStr);
      $type = $typeStr === 'Cr.' ? StatementType::CREDIT : StatementType::DEBIT;

      // Hapus bagian nominal dan saldo dari konten untuk mendapatkan deskripsi
      $content = preg_replace('/\s*(Db\.|Cr\.)\s*[\d\.]+,\d{2}\s*[\d\.]+,\d{2}.*$/', '', $content);
    }

    // Bersihkan deskripsi
    $description = $this->cleanDescription($content);

    if (empty($description)) {
      $description = 'Transaksi BNI';
    }

    return [
      'date' => $date,
      'description' => $description,
      'amount' => $amount,
      'type' => $type,
    ];
  }

  /**
  * Bersihkan teks deskripsi dari karakter yang tidak perlu.
  */
  private function cleanDescription(string $text): string
  {
    // Hapus tag HTML yang tersisa
    $text = strip_tags($text);

    // Hapus karakter newline dan tab
    $text = str_replace(["\n", "\r", "\t"], ' ', $text);

    // Hapus multiple spaces
    $text = preg_replace('/\s+/', ' ', $text);

    // Hapus teks yang tidak relevan (saldo, dll)
    $text = preg_replace('/[\d\.]+,\d{2}$/', '', $text);

    return trim($text);
  }

  /**
  * Format hasil akhir sesuai kontrak.
  */
  private function formatTransactions(array $transactions): array
  {
    return array_values(array_filter($transactions, fn($t) => $t['amount'] != 0));
  }
}