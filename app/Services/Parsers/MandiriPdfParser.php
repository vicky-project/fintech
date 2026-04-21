<?php

namespace Modules\FinTech\Services\Parsers;

use Modules\FinTech\Contracts\BankParserInterface;
use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class MandiriPdfParser extends AbstractBankParser implements BankParserInterface
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
    "of",
    " - ",
    "Date",
    "Tanggal",
    "Balance (IDR)",
    "SALDO (IDR)",
    "Tanggal\tSaldo (IDR)",
    "Nominal (IDR)",
    "Amount (IDR)",
    "Keterangan",
    "Remarks",
    "Tabungan Mandiri",
    "Dicetak pada/Issued on"
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
    $lines = $this->prepareLines($text);
    \Log::debug("Mandiri Text extract", ["lines" => $lines]);
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
      if (str_contains($line, "Tabungan Mandiri")) {
        $startIndex = $i;
        break;
      }
    }

    return collect($lines)
    ->map(fn($line) => trim($line))
    ->filter(fn($line) => !empty($line))
    ->slice($startIndex)
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
    return false;
  }

  private function extractTransactions(array $lines): array
  {
    $transactions = [];
    $currentBlock = [];
    $i = 0;
    $total = count($lines);

    while ($i < $total) {
      $line = $lines[$i];

      if (str_contains($line, 'WIB') && isset($lines[$i+1]) && $this->isDateLine($lines[$i+1])) {
        $currentBlock[] = $line;
        $currentBlock[] = $lines[$i+1];
        $i += 2;

        $transaction = $this->parseTransactionBlock($currentBlock);
        if ($transaction) {
          $transactions[] = $transaction;
        }
        $currentBlock = [];
        continue;
      }

      $currentBlock[] = $line;
      $i++;
    }

    if (!empty($currentBlock)) {
      $transaction = $this->parseTransactionBlock($currentBlock);
      if ($transaction) {
        $transactions[] = $transaction;
      }
    }

    return $transactions;
  }

  private function parseTransactionBlock(array $block): ?array
  {
    if (empty($block)) return null;

    $fullText = implode(' ', $block);

    // 1. Cari tanggal
    $date = null;
    foreach ($block as $line) {
      if ($this->isDateLine($line)) {
        $date = $this->parseDate($line);
        break;
      }
    }
    if (!$date) return null;

    // 2. Cari nominal transaksi
    $amount = 0;
    $type = StatementType::UNKNOWN;

    if (preg_match('/\s\d+\s+([+-][\d\.]+,\d{2})/', $fullText, $matches)) {
      $amountStr = $matches[1];
      $amount = abs($this->parseAmount($amountStr)); // Nilai absolut
      $type = str_contains($amountStr, '+') ? StatementType::CREDIT : StatementType::DEBIT;
    } else {
      if (preg_match('/[+-][\d\.]+,\d{2}/', $fullText, $matches)) {
        $amountStr = $matches[0];
        $amount = abs($this->parseAmount($amountStr));
        $type = str_contains($amountStr, '+') ? StatementType::CREDIT : StatementType::DEBIT;
      }
    }

    if ($amount == 0) return null;

    // 3. Bangun deskripsi
    $descriptionParts = [];
    foreach ($block as $line) {
      if ($this->isDateLine($line) || $this->isTimeLine($line)) continue;

      // Hapus pola nomor urut+nominal
      $line = preg_replace('/\s\d+\s+[+-][\d\.]+,\d{2}/', '', $line);
      // Hapus saldo di akhir baris
      $line = preg_replace('/\s+[\d\.]+,\d{2}$/', '', $line);

      $line = str_replace(':', '', $line);
      // Hapus awalan "161 dari" jika masih ada
      $line = preg_replace('/^\d+\s*dari\s*/', '', $line);
      $line = trim($line);
      if (!empty($line)) {
        $descriptionParts[] = $line;
      }
    }

    $description = implode(' ', $descriptionParts);
    $description = preg_replace('/\s+/', ' ', $description);
    $description = preg_replace('/\d{1,2} [A-Za-z]{3} \d{4} \- \d{1,2} [A-Za-z]{3} \d{4}/', '', $description);
    \Log::debug("Description parts", ["line" => $line]);

    // 4. Deteksi transfer berdasarkan kata kunci di deskripsi
    if ($type === StatementType::UNKNOWN) {
      $type = StatementType::fromDescription($description, $amountStr ? $this->parseAmount($amountStr) : 0);
    }

    return [
      'date' => $date,
      'description' => trim($description),
      'amount' => $amount,
      'type' => $type,
    ];
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

  private function parseDate(string $line): string
  {
    return Carbon::createFromFormat('d M Y', trim($line))->toDateString();
  }

  private function determineType(string $description): StatementType
  {
    $lower = strtolower($description);
    $debitKeywords = ['debit',
      'db',
      'tarik',
      'withdrawal',
      'pembayaran',
      'payment',
      'biaya',
      'adm',
      'keluar',
      'penarikan'];
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