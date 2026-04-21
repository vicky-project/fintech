<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class MandiriPdfParser extends AbstractBankParser
{
  protected string $bankCode = 'mandiri';

  protected array $skipPatterns = [
    "Plaza Mandiri",
    "e-Statement",
    "Nama/Name",
    "Cabang/Branch",
    "Periode/Period",
    "Nomor Rekening/Account Number",
    "Mata Uang/Currency",
    "Saldo Awal/Initial Balance",
    "Dana Masuk/Incoming Transactions",
    "Dana Keluar/Outgoing Transactions",
    "Saldo Akhir/Closing Balance",
    "PT Bank Mandiri",
    "Mandiri Call 14000",
    " (LPS)",
    "KCP ",
    "of",
    "No",
    "Date",
    "Tanggal",
    "Balance (IDR)",
    "SALDO (IDR)",
    "Tanggal\tSaldo (IDR)",
    "Nominal (IDR)",
    "Amount (IDR)",
    "Keterangan",
    "Remarks",
  ];

  public function canParse(string $filePath, ?string $content = null): bool
  {
    if (!str_ends_with(strtolower($filePath), '.pdf')) return false;

    $text = $content ?? $this->extractText($filePath);
    $patterns = [
      "/Plaza Mandiri/",
      "/e-Statement/",
      "/Tabungan Mandiri/",
      "/Mandiri Call 14000/",
      "/PT Bank Mandiri.*OJK.*BI.*LPS/",
      "/Saldo Awal\/Initial Balance/",
      "/Dana Masuk\/Incoming Transactions/",
      "/Dana Keluar\/Outgoing Transactions/",
      "/Saldo Akhir\/Closing Balance/",
    ];

    $matches = 0;
    foreach ($patterns as $pattern) {
      if (preg_match($pattern, $text)) {
        $matches++;
      }
    }
    return $matches >= 3;
  }

  public function parse(string $filePath): array
  {
    $text = $this->extractText($filePath);
    \Log::debug("Text extract", [
      'text' => $text
    ]);
    $lines = $this->prepareLines($text);
    \Log::debug("Lines", [
      'lines' => $lines
    ]);
    $transactions = $this->extractTransactions($lines);
    \Log::debug("Transaction extract", [
      'transaksi' => $transactions
    ]);
    return $this->formatTransactions($transactions);
  }

  /**
  * Ekstrak teks dari PDF (mendukung multi-halaman).
  */
  private function extractText(string $filePath): string
  {
    $text = parent::extractPdfText($filePath);
    // Normalisasi: ganti multiple newline dengan satu, hapus spasi berlebih
    $text = preg_replace("/\n\s*\n/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    return $text;
  }

  /**
  * Bersihkan dan siapkan baris teks.
  */
  private function prepareLines(string $content): array
  {
    $lines = explode("\n", $content);

    // Cari posisi "Tabungan Mandiri" sebagai awal data
    $startIndex = 0;
    foreach ($lines as $i => $line) {
      if (str_contains($line, "Tabungan Mandiri")) {
        $startIndex = $i;
        break;
      }
    }

    return collect($lines)
    ->slice($startIndex)
    ->map(fn($line) => trim($line))
    ->filter(fn($line) => !empty($line))
    ->filter(fn($line) => !$this->shouldSkipLine($line))
    ->filter(fn($line) => $this->isRelevantLine($line))
    ->values()
    ->all();
  }

  /**
  * Cek apakah baris harus dilewati.
  */
  private function shouldSkipLine(string $line): bool
  {
    foreach ($this->skipPatterns as $pattern) {
      if (str_contains($line, $pattern)) {
        return true;
      }
    }
    return false;
  }

  /**
  * Cek apakah baris relevan (bukan header atau info lain).
  */
  private function isRelevantLine(string $line): bool
  {
    // Baris dengan ":" biasanya info akun, kecuali time (07:02:54)
    if (str_contains($line, ":") && !$this->isTimeLine($line)) {
      return false;
    }
    // Baris dengan "-" yang bukan tanggal dan bukan tab
    if (str_contains($line, "-") && !str_contains($line, "\t") && !$this->isDateLine($line)) {
      return false;
    }
    return true;
  }

  /**
  * Ekstrak transaksi dari baris yang sudah dibersihkan.
  */
  private function extractTransactions(array $lines): array
  {
    if (empty($lines)) {
      return [];
    }

    $transactions = [];
    $lastDateIndex = 0;

    foreach ($lines as $key => $line) {
      if ($this->isDateLine($line)) {
        // Jika jarak dengan tanggal sebelumnya terlalu dekat, abaikan
        if ($key - $lastDateIndex <= 2) {
          $lastDateIndex = $key;
          continue;
        }

        // Ambil semua baris antara tanggal ini dan tanggal sebelumnya
        $currentTransaction = [];
        for ($i = $key; $i > $lastDateIndex; $i--) {
          $currentTransaction[] = $lines[$i];
        }
        $transactions[] = $currentTransaction;
        $lastDateIndex = $key;
      }
    }

    return $this->populateDataTransaction($transactions);
  }

  /**
  * Populasi data transaksi dari kumpulan baris.
  */
  private function populateDataTransaction(array $transactions): array
  {
    $data = [];
    foreach ($transactions as $transaction) {
      $current = [];
      $descriptions = [];

      foreach ($transaction as $line) {
        if ($this->isDateLine($line)) {
          $current['date'] = $this->parseDate($line);
          continue;
        }
        if ($this->isTimeLine($line)) {
          $current['time'] = $line;
          continue;
        }
        if ($this->isAmountLine($line)) {
          $current['amount'] = $this->extractAmount($line);
          $descPart = $this->extractDescriptionFromAmountLine($line);
          if ($descPart) {
            $descriptions[] = $descPart;
          }
          continue;
        }
        $descriptions[] = $line;
      }

      // Bangun deskripsi
      $current['description'] = $this->buildDescription($descriptions);

      // Tentukan tipe transaksi
      $current['type'] = $this->determineType($current['description'], $current['amount'] ?? '');

      if (isset($current['date']) && isset($current['amount'])) {
        $data[] = $current;
      }
    }

    return $data;
  }

  /**
  * Format hasil akhir sesuai kontrak.
  */
  private function formatTransactions(array $transactions): array
  {
    return array_map(function ($trx) {
      return [
        'date' => $trx['date'],
        'description' => $trx['description'],
        'amount' => $this->parseAmount($trx['amount']), // pakai helper parent
        'type' => $trx['type'],
      ];
    }, $transactions);
  }

  // ==================== HELPER DETEKSI ====================

  private function isDateLine(string $line): bool
  {
    return (bool) preg_match('/^\d{1,2} [A-Za-z]{3} \d{4}$/', $line);
  }

  private function isTimeLine(string $line): bool
  {
    return (bool) preg_match('/^\d{1,2}:\d{2}:\d{2} [A-Za-z]+$/', $line);
  }

  private function isAmountLine(string $line): bool
  {
    return str_contains($line, "\t") &&
    preg_match('/[+-]?\d{1,3}(?:\.\d{3})*(?:,\d{2})$/', $line);
  }

  // ==================== HELPER EKSTRAKSI ====================

  private function parseDate(string $line): string
  {
    return Carbon::createFromFormat('d M Y', $line)->toDateString();
  }

  private function extractAmount(string $line): string
  {
    $parts = explode("\t", $line);
    $amount = end($parts);
    return preg_replace("/[^\d.,+-]/", "", $amount);
  }

  private function extractDescriptionFromAmountLine(string $line): ?string
  {
    $parts = explode("\t", $line);
    if (count($parts) >= 3) {
      $desc = trim($parts[0]);
      return !empty($desc) ? $desc : null;
    }
    return null;
  }

  private function buildDescription(array $descriptions): string
  {
    return collect($descriptions)
    ->reverse()
    ->filter()
    ->map(fn($d) => trim($d))
    ->implode(' ');
  }

  /**
  * Tentukan tipe transaksi berdasarkan deskripsi dan nominal.
  */
  private function determineType(string $description, string $amount): StatementType
  {
    // Jika nominal mengandung minus, pasti debit
    if (str_contains($amount, '-')) {
      return StatementType::DEBIT;
    }

    $lower = strtolower($description);
    $debitKeywords = ['debit',
      'db',
      'tarik',
      'withdrawal',
      'pembayaran',
      'payment',
      'biaya',
      'adm',
      'keluar'];
    $creditKeywords = ['credit',
      'cr',
      'setor',
      'deposit',
      'masuk',
      'incoming',
      'transfer masuk'];

    foreach ($debitKeywords as $kw) {
      if (str_contains($lower, $kw)) return StatementType::DEBIT;
    }
    foreach ($creditKeywords as $kw) {
      if (str_contains($lower, $kw)) return StatementType::CREDIT;
    }

    return StatementType::UNKNOWN;
  }
}