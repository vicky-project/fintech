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
    "Dicetak pada/Issued on",
    "Tabungan Mandiri",
  ];

  public function canParse(string $filePath, ?string $content = null): bool
  {
    if (!str_ends_with(strtolower($filePath), '.pdf')) {
      return false;
    }

    $text = $content ?? $this->extractText($filePath);
    $patterns = [
      "/Plaza Mandiri/",
      "/e-Statement/",
      "/Tabungan Mandiri/",
      "/Mandiri Call 14000/",
      "/PT Bank Mandiri.*OJK.*BI.*LPS/",
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
    $lines = $this->prepareLines($text);
    \Log::debug("Mandiri lines", ["lines" => $lines]);
    $transactions = $this->extractTransactions($lines);
    return $this->formatTransactions($transactions);
  }

  private function extractText(string $filePath): string
  {
    $text = parent::extractPdfText($filePath);
    $text = preg_replace("/\n\s*\n/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);
    return $text;
  }

  private function prepareLines(string $content): array
  {
    $lines = explode("\n", $content);

    $startIndex = 0;
    foreach ($lines as $i => $line) {
      // Cari awal data transaksi (setelah header "No Tanggal Keterangan...")
      if (str_contains($line, "No") && str_contains($line, "Tanggal") && str_contains($line, "Keterangan")) {
        $startIndex = $i + 1;
        break;
      }
    }

    return collect($lines)
    ->slice($startIndex)
    ->map(fn($line) => trim($line))
    ->filter(fn($line) => !empty($line))
    ->filter(fn($line) => !$this->shouldSkipLine($line))
    ->values()
    ->all();
  }

  private function shouldSkipLine(string $line): bool
  {
    foreach ($this->skipPatterns as $pattern) {
      if (str_contains($line, $pattern)) {
        return true;
      }
    }
    // Skip nomor halaman "161 dari"
    if (preg_match('/^\d+\s*dari/', $line)) {
      return true;
    }
    // Skip baris yang hanya nomor (tanpa informasi lain)
    if (preg_match('/^\d+$/', $line)) {
      return true;
    }
    return false;
  }

  private function extractTransactions(array $lines): array
  {
    $transactions = [];

    foreach ($lines as $line) {
      $transaction = $this->parseTransactionLine($line);
      if ($transaction) {
        $transactions[] = $transaction;
      }
    }

    return $transactions;
  }

  /**
  * Parse satu baris transaksi.
  * Pola: "NomorUrut DD MMM YYYY HH:MM:SS WIB Keterangan Nominal Saldo"
  * Contoh: "1 01 Jul 2024 10:38:49 WIB Penarikan tunai di ATM BANK MANDIRI JBR CB AMBULU 02 -600,000.00 1,552,668.52"
  */
  private function parseTransactionLine(string $line): ?array
  {
    // Pola: nomor urut (1 atau lebih digit), spasi, tanggal, spasi, waktu WIB, spasi, keterangan, nominal, saldo
    // Nominal selalu memiliki tanda + atau - di depan, format ribuan dengan koma atau titik
    // Saldo adalah angka di akhir baris
    $pattern = '/^(\d+)\s+(\d{1,2}\s+[A-Za-z]{3}\s+\d{4})\s+(\d{1,2}:\d{2}:\d{2}\s+WIB)\s+(.+?)\s+([+-][\d,\.]+)\s+([\d,\.]+)$/u';

    if (preg_match($pattern, $line, $matches)) {
      $dateStr = $matches[2];
      $description = trim($matches[4]);
      $amountStr = $matches[5];
      // Saldo diabaikan

      $date = $this->parseDate($dateStr);
      $amount = abs($this->parseAmount($amountStr));
      $type = str_contains($amountStr, '+') ? StatementType::CREDIT : StatementType::DEBIT;

      return [
        'date' => $date,
        'description' => $description,
        'amount' => $amount,
        'type' => $type,
      ];
    }

    return null;
  }

  private function formatTransactions(array $transactions): array
  {
    return array_values(array_filter($transactions, fn($t) => $t['amount'] != 0));
  }

  private function parseDate(string $dateStr): string
  {
    // Format: "01 Jul 2024"
    return Carbon::createFromFormat('d M Y', trim($dateStr))->toDateString();
  }
}