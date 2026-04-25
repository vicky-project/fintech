<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class BriPdfParser extends AbstractBankParser
{
  protected string $bankCode = 'bri';

  protected array $skipPatterns = [
    'BANK RAKYAT INDONESIA',
    'BRI',
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
    if (!str_ends_with(strtolower($filePath), '.pdf')) {
      return false;
    }

    $text = $content ?? $this->extractText($filePath);

    $briPatterns = [
      'BANK RAKYAT INDONESIA',
      'BRI',
      'Saldo Awal',
      'Total Debit',
      'Total Credit',
      'Saldo Akhir',
      'Terbilang',
    ];

    $matches = 0;
    foreach ($briPatterns as $pattern) {
      if (stripos($text, $pattern) !== false) {
        $matches++;
      }
    }

    return $matches >= 3;
  }

  public function parse(string $filePath): array
  {
    $text = $this->extractText($filePath);
    $currency = $this->extractCurrency($text);
    \Log::debug("Bri parser.", ['data' => $text]);
    $lines = $this->prepareLines($text);

    \Log::debug("BRI lines", ['lines' => $lines]);

    $transactions = $this->extractTransactions($lines);
    return $this->formatTransactions($transactions);
  }

  /**
  * Ekstrak teks dari PDF dan bersihkan header/footer.
  */
  private function extractText(string $filePath): string
  {
    $text = parent::extractPdfText($filePath);

    // Hapus tag HTML yang mungkin terbawa
    $text = strip_tags($text);

    // Hapus header halaman (angka 1 yang berulang)
    $text = preg_replace('/\b\d{1,3}\s+\d{1,3}\s+/', '', $text);
    $text = preg_replace('/===== Page \d+ =====/i', '', $text);
    $text = preg_replace('/\d+\s*dari\s*\d+/i', '', $text);

    // Normalisasi spasi
    $text = preg_replace("/\n\s*\n/", "\n", $text);
    $text = preg_replace("/[ \t]+/", " ", $text);

    return trim($text);
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
    return false;
  }

  /**
  * Ekstrak transaksi dari baris teks.
  */
  private function extractTransactions(array $lines): array
  {
    $transactions = [];
    $fullText = implode("\n", $lines);

    // Pola transaksi: tanggal (DD/MM/YY HH:MM:SS) diikuti keterangan, lalu debit, kredit, saldo
    // Contoh: "29/03/17 15:30:23BY 2SRT REF BANK NOB017 TGL270317 PT AMI300,000.000.0010,399,046,420.91"
    // Kita perlu memisahkan dengan benar

    // Cari semua tanggal sebagai anchor
    preg_match_all('/(\d{2}\/\d{2}\/\d{2}\s+\d{2}:\d{2}:\d{2})/', $fullText, $dateMatches, PREG_OFFSET_CAPTURE);

    if (empty($dateMatches[0])) {
      return $transactions;
    }

    for ($i = 0; $i < count($dateMatches[0]); $i++) {
      $currentDate = $dateMatches[0][$i][0];
      $currentPos = $dateMatches[0][$i][1] + strlen($currentDate);
      $nextPos = isset($dateMatches[0][$i + 1]) ? $dateMatches[0][$i + 1][1] : strlen($fullText);

      $block = trim(substr($fullText, $currentPos, $nextPos - $currentPos));

      // Ekstrak keterangan dan nominal
      $transaction = $this->parseTransactionBlock($currentDate, $block);
      if ($transaction) {
        $transactions[] = $transaction;
      }
    }

    return $transactions;
  }

  /**
  * Parse satu blok transaksi.
  */
  private function parseTransactionBlock(string $dateStr, string $block): ?array
  {
    // Normalisasi tanggal
    $date = Carbon::createFromFormat('d/m/y H:i:s', $dateStr)?->toDateString();
    if (!$date) return null;

    // Cari nominal di akhir blok
    // Pola: keterangan diikuti oleh debit (opsional), kredit (opsional), saldo (angka)
    // Nominal bisa berisi koma dan titik
    if (!preg_match('/([\d,]+\.\d{2})$/', $block, $saldoMatch)) {
      return null;
    }

    $saldo = $saldoMatch[1];
    $remaining = trim(substr($block, 0, -strlen($saldo)));

    // Tentukan debit atau kredit
    $amount = 0;
    $type = StatementType::UNKNOWN;

    // Cari nominal sebelum saldo (bisa debit atau kredit)
    if (preg_match('/([\d,]+\.\d{2})$/', $remaining, $nominalMatch)) {
      $nominalStr = $nominalMatch[1];
      $amount = $this->parseAmount($nominalStr);
      $remaining = trim(substr($remaining, 0, -strlen($nominalStr)));

      // Cek apakah ada debit atau kredit sebelumnya
      if (preg_match('/([\d,]+\.\d{2})$/', $remaining, $prevMatch)) {
        // Ada dua nominal: yang pertama debit, kedua kredit? atau sebaliknya
        $prevAmount = $this->parseAmount($prevMatch[1]);
        $prevRemaining = trim(substr($remaining, 0, -strlen($prevMatch[1])));

        // Dari contoh: keterangan diikuti dua angka (debit dan kredit)
        if ($prevAmount > 0 && $amount == 0) {
          // Debit ada, kredit 0.00
          $amount = $prevAmount;
          $type = StatementType::DEBIT;
          $description = $prevRemaining;
        } elseif ($prevAmount == 0 && $amount > 0) {
          // Kredit ada, debit 0.00
          $type = StatementType::CREDIT;
          $description = $prevRemaining;
        } else {
          // Ambil yang bukan nol
          if ($prevAmount > 0) {
            $amount = $prevAmount;
            $type = StatementType::DEBIT;
          } else {
            $type = StatementType::CREDIT;
          }
          $description = $prevRemaining;
        }
      } else {
        // Hanya satu nominal, tentukan dari konteks (jika ada 0.00 setelahnya)
        // Dari contoh: "300,000.000.0010,399,046,420.91" -> 300,000.00 (debit) dan 10,399,046,420.91 (saldo)
        // Atau "0.00400,000,000.0010,799,046,420.91" -> 0.00 (debit), 400,000,000.00 (kredit), 10,799,046,420.91 (saldo)

        // Periksa kembali remaining, mungkin ada dua nominal sebelum saldo
        if (preg_match('/([\d,]+\.\d{2})\s+([\d,]+\.\d{2})$/', $remaining, $dualMatch)) {
          $first = $this->parseAmount($dualMatch[1]);
          $second = $this->parseAmount($dualMatch[2]);

          if ($first > 0) {
            $amount = $first;
            $type = StatementType::DEBIT;
          } else {
            $amount = $second;
            $type = StatementType::CREDIT;
          }

          $description = trim(substr($remaining, 0, strpos($remaining, $dualMatch[0])));
        } else {
          $description = $remaining;
          $type = StatementType::UNKNOWN;
        }
      }
    } else {
      $description = $remaining;
    }

    // Bersihkan deskripsi
    $description = trim(preg_replace('/\s+/', ' ', $description));

    return [
      'date' => $date,
      'description' => $description ?: 'Transaksi BRI',
      'amount' => $amount,
      'type' => $type,
    ];
  }

  /**
  * Format hasil akhir.
  */
  private function formatTransactions(array $transactions): array
  {
    return array_values(array_filter($transactions, fn($t) => $t['amount'] != 0 && $t['type'] !== StatementType::UNKNOWN));
  }

  /**
  * Deteksi mata uang dari teks PDF.
  */
  public function extractCurrency(string $text): string
  {
    $normalized = preg_replace('/\s+/', '', $text);

    if (preg_match('/(?:Currency|MataUang):.*?([A-Z]{3})(?![a-zA-Z])/i', $normalized, $matches)) {
      return strtoupper($matches[1]);
    }

    return $this->currency;
  }
}