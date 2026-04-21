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
    $lines = $this->prepareLines($text);
    \Log::debug("Text extract", [
      "lines" => $lines]);
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
    return false;
  }

  /**
  * Ekstrak transaksi dengan logika baru:
  * Setiap transaksi diakhiri oleh baris yang mengandung pola "WIB" diikuti tanggal.
  */
  private function extractTransactions(array $lines): array
  {
    $transactions = [];
    $current = [];
    $i = 0;
    $totalLines = count($lines);

    while ($i < $totalLines) {
      $line = $lines[$i];

      // Deteksi akhir transaksi: baris dengan format "WIB" lalu diikuti tanggal di baris berikutnya
      if (str_contains($line, 'WIB') && isset($lines[$i+1]) && $this->isDateLine($lines[$i+1])) {
        // Tambahkan baris WIB dan tanggal ke current
        $current[] = $line;
        $current[] = $lines[$i+1];
        $i += 2;

        // Simpan transaksi yang sudah terkumpul
        if (!empty($current)) {
          $transactions[] = $this->parseTransactionBlock($current);
          $current = [];
        }
        continue;
      }

      // Jika baris adalah tanggal yang tidak didahului WIB, mungkin awal transaksi tanpa waktu
      if ($this->isDateLine($line) && (empty($current) || !str_contains(end($current), 'WIB'))) {
        if (!empty($current)) {
          $transactions[] = $this->parseTransactionBlock($current);
          $current = [];
        }
        $current[] = $line;
        $i++;
        continue;
      }

      $current[] = $line;
      $i++;
    }

    // Proses sisa blok terakhir
    if (!empty($current)) {
      $transactions[] = $this->parseTransactionBlock($current);
    }

    return $transactions;
  }

  /**
  * Parse satu blok transaksi (kumpulan baris) menjadi data terstruktur.
  */
  private function parseTransactionBlock(array $block): ?array
  {
    if (empty($block)) return null;

    // Cari tanggal (format "DD MMM YYYY")
    $date = null;
    $descriptionParts = [];
    $amountStr = null;
    $type = StatementType::UNKNOWN;

    // Gabungkan semua baris untuk pencarian amount
    $fullText = implode(' ', $block);

    // Cari nominal dengan format seperti "+700.000,00" atau "-2.500,00" atau "1.071.983,52"
    if (preg_match('/[+-]?[\d\.]+,\d{2}/', $fullText, $matches)) {
      $amountStr = $matches[0];
    }

    foreach ($block as $line) {
      // Ambil tanggal
      if (!$date && $this->isDateLine($line)) {
        $date = $this->parseDate($line);
        continue;
      }
      // Abaikan waktu
      if ($this->isTimeLine($line)) {
        continue;
      }
      // Abaikan baris yang hanya berisi nominal (sudah diambil)
      if (preg_match('/^[+-]?[\d\.]+,\d{2}$/', trim($line))) {
        continue;
      }
      // Abaikan baris dengan nomor urut dan nominal
      if (preg_match('/^\d+\s+[+-]?[\d\.]+,\d{2}/', $line)) {
        continue;
      }
      // Abaikan baris "dari" atau nomor halaman
      if (preg_match('/^\d+\s*dari/', $line)) {
        continue;
      }

      $descriptionParts[] = $line;
    }

    $description = implode(' ', array_filter($descriptionParts));
    $description = preg_replace('/\s+/', ' ', $description);

    if (!$date || !$amountStr) {
      return null;
    }

    // Tentukan tipe dari nominal (jika ada tanda + atau -)
    if (str_contains($amountStr, '+')) {
      $type = StatementType::CREDIT;
    } elseif (str_contains($amountStr, '-')) {
      $type = StatementType::DEBIT;
    } else {
      // Jika tidak ada tanda, coba deteksi dari deskripsi
      $type = $this->determineType($description, $amountStr);
    }

    $amount = $this->parseAmount($amountStr);

    return [
      'date' => $date,
      'description' => trim($description),
      'amount' => $amount,
      'type' => $type,
    ];
  }

  private function formatTransactions(array $transactions): array
  {
    return array_filter($transactions);
  }

  // ==================== HELPER DETEKSI ====================

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

  private function determineType(string $description, string $amount): StatementType
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