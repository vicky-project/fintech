<?php

namespace Modules\FinTech\Services\Parsers;

use Modules\FinTech\Contracts\BankParserInterface;
use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class MandiriPdfParser extends AbstractBankParser implements BankParserInterface
{
  protected string $bankCode = 'mandiri';

  protected array $headerPatterns = [
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
  ];

  public function matches(string $content): bool
  {
    $mandiriPatterns = [
    ];
    return collect($mandiriPatterns)
    ->filter(fn($pattern) => preg_match($pattern, $content))
    ->count() >= 3;
  }

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
    $lines = $this->prepareLines($text);
    $transactions = $this->extractTransactions($lines);
    return $this->formatTransactions($transactions);
  }

  private function extractText(string $filePath): string
  {
    return parent::extractPdfText($filePath);
  }

  private function prepareLines(string $content): array
  {
    $lines = explode("\n", $content);

    return collect($lines)
    ->map(fn($line) => trim($line))
    ->filter(fn($line) => !empty($line))
    ->slice(array_search("Tabungan Mandiri", $lines))
    ->filter(fn($line) => !$this->shouldSkipLine($line))
    ->filter(fn($line) => !in_array($line, $this->headerPatterns))
    ->filter(fn($line) => $this->isRelevantLine($line))
    ->values()
    ->all();
  }

  private function shouldSkipLine(string $line): bool
  {
    return collect($this->skipPatterns)->contains(
      fn($pattern) => str_contains($line, $pattern)
    );
  }

  private function extractTransactions(array $lines): array
  {
    if (empty($lines)) {
      dd($lines);
    }
    $transactions = [];
    $lastDateIndex = 0;
    foreach ($lines as $key => $line) {
      // Cari tanggal sebagai indikator
      if ($this->isDateLine($line)) {
        // Jika tidak ada deskripsi dan nominal berarti bukan transaksi
        if ($key - $lastDateIndex <= 2) {
          $lastDateIndex = $key;
          continue;
        }

        $currentTransaction = [];
        // Ambil data diatas tanggal sampai batas tanggal pada index sebelumnya
        for ($i = $key; $i > $lastDateIndex; $i--) {
          $currentTransaction[] = $lines[$i];
        }
        $transactions[] = $currentTransaction;
        // reset index terakhir untuk acuan dsta berikutnya
        $lastDateIndex = $key;
      }
    }

    return $this->populateDataTransaction($transactions);
  }

  private function populateDataTransaction(array $transactions): array
  {
    $data = [];
    foreach ($transactions as $transaction) {
      $currentTransaction = [];
      $currentDescriptions = [];
      foreach ($transaction as $line) {
        if ($this->isDateLine($line)) {
          $currentTransaction["date"] = $line;
          continue;
        }
        if ($this->isTimeLine($line)) {
          $currentTransaction["time"] = $line;
          continue;
        }
        if ($this->isAmountLine($line)) {
          $currentTransaction["amount"] = $this->extractAmount($line);
          $currentTransaction["type"] = $this->determineType($line);
          $descriptionPart = $this->extractDescriptionFromAmountLine($line);
          if ($descriptionPart) {
            $currentDescriptions[] = $descriptionPart;
          }
          continue;
        }
        $currentDescriptions[] = $line;
      }

      $currentTransaction["description"] = $this->buildDescription(
        $currentDescriptions
      );
      $data[] = $currentTransaction;
    }

    return $data;
  }

  /**
  * Check if line matches amount pattern (e.g., "2.669.917,521\t-2.800,00")
  */
  private function isAmountLine(string $line): bool
  {
    return str_contains($line, "\t") &&
    preg_match('/[+-]?\d{1,3}(?:\.\d{3})*(?:,\d{2})$/', $line);
  }

  private function extractAmount(string $line): string
  {
    $parts = explode("\t", $line);
    $amount = end($parts);

    $amount = preg_replace("/[^\d.,+-]/", "", $amount);

    return abs($this->parseAmount($amount));
  }


  private function isRelevantLine(string $line): bool
  {
    if (str_contains($line, ":") && !$this->isTimeLine($line)) {
      return false;
    }
    if (
      str_contains($line, "-") &&
      !str_contains($line, "\t") &&
      !$this->isDateLine($line)
    ) {
      return false;
    }

    return true;
  }

  private function formatTransactions(array $transactions): array
  {
    return array_values(array_filter($transactions, fn($t) => $t['amount'] != 0));
  }

  private function isDateLine(string $line): bool
  {
    return (bool) preg_match('/^\d{1,2} [A-Za-z]{3} \d{4}$/', trim($line));
  }

  private function isTimeLine(string $line): bool
  {
    return (bool) preg_match('/^\d{1,2}:\d{2}:\d{2} [A-Za-z]+$/', trim($line));
  }

  private function extractDescriptionFromAmountLine(string $line): ?string
  {
    $parts = explode("\t", $line);
    if (count($parts) >= 3) {
      $description = $parts[0];
      return !empty(trim($description)) ? trim($description) : null;
    }
    return null;
  }

  private function buildDescription(array $descriptions): string
  {
    $desc = collect($descriptions)
    ->reverse()
    ->filter()
    ->map(fn($desc) => trim($desc))
    ->implode(" ");

    $desc = preg_replace('/^\d+\s*dari\s*/', '', $desc);
  }

  private function determineType(string $amount): StatementType
  {
    return str_contains($amount, '+') ? StatementType::CREDIT : (str_contains($amount, '-') ? StatementType::DEBIT : StatementType::UNKNOWN);
  }
}