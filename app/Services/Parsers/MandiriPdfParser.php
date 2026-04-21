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
    "Dicetak pada/Issued on",
    "Tabungan Mandiri",
    ":"
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
    \Log::debug("extract text", ["text" => $text]);
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

    // Skip baris periode (contoh: "01 Jan 2023 - 30 Jun 2023")
    if (preg_match('/^\d{1,2} [A-Za-z]{3} \d{4} - \d{1,2} [A-Za-z]{3} \d{4}$/', $line)) {
      return true;
    }

    return false;
  }

  /**
  * Ekstrak transaksi dengan memotong berdasarkan kemunculan baris nominal yang valid.
  */
  private function extractTransactions(array $lines): array
  {
    $transactions = [];
    $currentBlock = [];
    $i = 0;
    $total = count($lines);

    while ($i < $total) {
      $line = $lines[$i];

      // Deteksi baris yang mengandung nominal transaksi (pola: digit+spasi+tanda+nominal)
      if (preg_match('/\s\d+\s+[+-][\d\.]+,\d{2}/', $line)) {
        // Jika sudah ada blok sebelumnya, proses dulu
        if (!empty($currentBlock)) {
          $transaction = $this->parseTransactionBlock($currentBlock);
          if ($transaction) {
            $transactions[] = $transaction;
          }
          $currentBlock = [];
        }
        // Mulai blok baru dengan baris ini
        $currentBlock[] = $line;
        $i++;
        continue;
      }

      // Jika baris mengandung "WIB" dan diikuti tanggal, kemungkinan akhir transaksi
      if (str_contains($line, 'WIB') && isset($lines[$i+1]) && $this->isDateLine($lines[$i+1])) {
        $currentBlock[] = $line;
        $currentBlock[] = $lines[$i+1];
        $i += 2;

        // Proses blok dan reset
        $transaction = $this->parseTransactionBlock($currentBlock);
        if ($transaction) {
          $transactions[] = $transaction;
        }
        $currentBlock = [];
        continue;
      }

      // Tambahkan ke blok saat ini
      $currentBlock[] = $line;
      $i++;
    }

    // Proses blok terakhir jika ada
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
    if (empty($block)) {
      return null;
    }

    // Gabungkan untuk pencarian pola
    $fullText = implode(' ', $block);

    // 1. Cari tanggal (prioritas: tanggal yang paling akhir di blok)
    $date = null;
    // Cari dari belakang karena tanggal transaksi biasanya di akhir
    for ($i = count($block) - 1; $i >= 0; $i--) {
      if ($this->isDateLine($block[$i])) {
        $date = $this->parseDate($block[$i]);
        break;
      }
    }
    if (!$date) {
      return null;
    }

    // 2. Cari nominal transaksi
    $amountStr = null;
    if (preg_match('/\s\d+\s+([+-][\d\.]+,\d{2})/', $fullText, $matches)) {
      $amountStr = $matches[1];
    } else {
      if (preg_match('/[+-][\d\.]+,\d{2}/', $fullText, $matches)) {
        $amountStr = $matches[0];
      }
    }

    if (!$amountStr) {
      return null;
    }

    $amount = abs($this->parseAmount($amountStr));

    // 3. Bangun deskripsi (hanya ambil baris sebelum nominal dan bukan tanggal/waktu)
    $descriptionParts = [];
    foreach ($block as $line) {
      if ($this->isDateLine($line) || $this->isTimeLine($line)) {
        continue;
      }
      // Hentikan jika sudah mencapai baris yang mengandung nominal (karena setelah itu adalah saldo/nomor urut)
      if (preg_match('/\s\d+\s+[+-][\d\.]+,\d{2}/', $line)) {
        // Ambil hanya bagian sebelum pola nominal
        $line = preg_replace('/\s\d+\s+[+-][\d\.]+,\d{2}.*$/', '', $line);
        if (!empty(trim($line))) {
          $descriptionParts[] = trim($line);
        }
        continue;
      }
      // Hapus saldo di akhir baris (jika ada)
      $line = preg_replace('/\s+[\d\.]+,\d{2}$/', '', $line);
      // Hapus karakter ':'
      $line = str_replace(':', '', $line);

      $line = trim($line);
      if (!empty($line)) {
        $descriptionParts[] = $line;
      }
    }

    $description = implode(' ', $descriptionParts);
    $description = preg_replace('/\s+/', ' ', $description);
    $description = preg_replace('/\b[A-Z]{2,}\s+[A-Z\s]+\b/', '', $description); // Nama kapital
    $description = preg_replace('/\d{1,2} [A-Za-z]{3} \d{4} - \d{1,2} [A-Za-z]{3} \d{4}/', '', $description);
    $description = trim($description);

    // Batasi panjang deskripsi (misal 255 karakter)
    if (strlen($description) > 255) {
      $description = substr($description, 0, 252) . '...';
    }

    // 4. Tentukan tipe
    $type = StatementType::fromDescription($description, $amountStr);

    return [
      'date' => $date,
      'description' => $description ?: "Transaksi Bank Mandiri",
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
}