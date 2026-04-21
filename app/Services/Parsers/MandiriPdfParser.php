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
    "PRATAMA",
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

    // Cari awal data transaksi
    $startIndex = 0;
    foreach ($lines as $i => $line) {
      // Cari header tabel "No Tanggal Keterangan..."
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
    // Nomor halaman
    if (preg_match('/^\d+\s+of\s+\d+$/', $line)) {
      return true;
    }
    // Baris hanya ":"
    if (trim($line) === ':') {
      return true;
    }
    // Periode
    if (preg_match('/^\d{1,2} [A-Za-z]{3} \d{4} - \d{1,2} [A-Za-z]{3} \d{4}$/', $line)) {
      return true;
    }
    // Angka saldo berdiri sendiri
    if (preg_match('/^[\d\.]+,\d{2}$/', $line)) {
      return true;
    }
    return false;
  }

  private function extractTransactions(array $lines): array
  {
    $transactions = [];
    $i = 0;
    $total = count($lines);

    while ($i < $total) {
      // Cari baris yang mengandung nominal
      if ($this->isAmountLine($lines[$i])) {
        // Kumpulkan blok transaksi: deskripsi dari baris sebelumnya, nominal, waktu, tanggal
        $block = [];

        // Mundur untuk mengambil deskripsi
        $j = $i - 1;
        $descLines = [];
        while ($j >= 0 && !$this->isAmountLine($lines[$j]) && !$this->isTimeLine($lines[$j]) && !$this->isDateLine($lines[$j])) {
          array_unshift($descLines, $lines[$j]);
          $j--;
        }

        $block = array_merge($descLines, [$lines[$i]]);

        // Maju untuk mengambil waktu dan tanggal
        $j = $i + 1;
        while ($j < $total && ($this->isTimeLine($lines[$j]) || $this->isDateLine($lines[$j]))) {
          $block[] = $lines[$j];
          $j++;
        }

        $transaction = $this->parseTransactionBlock($block);
        if ($transaction) {
          $transactions[] = $transaction;
        }

        $i = $j;
        continue;
      }
      $i++;
    }

    return $transactions;
  }

  private function isAmountLine(string $line): bool
  {
    // Baris mengandung saldo dan nominal (nominal memiliki +/-)
    return preg_match('/[\d\.]+,\d{2}\s+[+-][\d\.]+,\d{2}$/', $line) === 1;
  }

  private function parseTransactionBlock(array $block): ?array
  {
    if (empty($block)) return null;

    // Cari baris nominal
    $amountLine = null;
    foreach ($block as $line) {
      if ($this->isAmountLine($line)) {
        $amountLine = $line;
        break;
      }
    }
    if (!$amountLine) return null;

    // Ekstrak nominal
    preg_match('/[+-][\d\.]+,\d{2}$/', $amountLine, $matches);
    if (empty($matches)) return null;

    $amountStr = $matches[0];
    $amount = abs($this->parseAmount($amountStr));
    $type = str_contains($amountStr, '+') ? StatementType::CREDIT : StatementType::DEBIT;

    // Cari tanggal di blok
    $date = null;
    foreach ($block as $line) {
      if ($this->isDateLine($line)) {
        $date = $this->parseDate($line);
        break;
      }
    }
    if (!$date) return null;

    // Deskripsi dari baris sebelum nominal
    $descriptionParts = [];
    $foundAmount = false;
    foreach ($block as $line) {
      if ($line === $amountLine) {
        $foundAmount = true;
        continue;
      }
      if ($foundAmount) break; // hanya ambil sebelum nominal

      if ($this->isTimeLine($line) || $this->isDateLine($line)) continue;

      $line = str_replace(':', '', $line);
      $line = trim($line);
      if (!empty($line)) {
        $descriptionParts[] = $line;
      }
    }

    $description = implode(' ', $descriptionParts);
    $description = preg_replace('/\s+/', ' ', $description);
    $description = trim($description);

    return [
      'date' => $date,
      'description' => $description ?: 'Transaksi Bank Mandiri',
      'amount' => $amount,
      'type' => $type,
    ];
  }

  private function formatTransactions(array $transactions): array
  {
    return array_values(array_filter($transactions, fn($t) => $t['amount'] != 0));
  }

  private function isTimeLine(string $line): bool
  {
    return (bool) preg_match('/^\d{1,2}:\d{2}:\d{2} [A-Z]+$/', trim($line));
  }

  private function isDateLine(string $line): bool
  {
    return (bool) preg_match('/^\d{1,2} [A-Za-z]{3} \d{4}$/', trim($line));
  }

  private function parseDate(string $line): string
  {
    return Carbon::createFromFormat('d M Y', trim($line))->toDateString();
  }
}