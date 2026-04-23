<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class MandiriExcelParser extends AbstractBankParser
{
  protected string $bankCode = 'mandiri';

  public function canParse(string $filePath, ?string $content = null): bool
  {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    return in_array($ext, ['xls', 'xlsx']);
  }

  public function parse(string $filePath): array
  {
    $rows = $this->readSpreadsheet($filePath);
    if (empty($rows)) {
      return [];
    }

    \Log::debug("Result read spreadsheet", ["data" => $rows]);

    // Cari baris header yang mengandung "No", "Tanggal", "Keterangan"
    $headerRowIndex = null;
    foreach ($rows as $i => $row) {
      $line = implode(' ', array_slice($row, 0, 10));
      if (str_contains($line, 'No') && str_contains($line, 'Tanggal') && str_contains($line, 'Keterangan')) {
        $headerRowIndex = $i;
        break;
      }
    }

    if ($headerRowIndex === null) {
      throw new \Exception("Format Excel Bank Mandiri tidak dikenali: header kolom tidak ditemukan.");
    }

    // Tentukan indeks kolom berdasarkan baris header (bisa 2 baris header)
    $headerRow1 = $rows[$headerRowIndex];
    $headerRow2 = ($headerRowIndex + 1 < count($rows)) ? $rows[$headerRowIndex + 1] : [];

    // Gabungkan informasi dari dua baris header untuk mendapatkan nama kolom yang lebih lengkap
    $colNames = [];
    for ($j = 0; $j < max(count($headerRow1), count($headerRow2)); $j++) {
      $part1 = isset($headerRow1[$j]) ? trim($headerRow1[$j]) : '';
      $part2 = isset($headerRow2[$j]) ? trim($headerRow2[$j]) : '';
      $colNames[$j] = trim($part1 . ' ' . $part2);
    }

    $colIndex = [
      'tanggal' => null,
      'keterangan' => null,
      'debit' => null,
      'credit' => null,
    ];

    foreach ($colNames as $j => $name) {
      $lower = strtolower($name);
      if (str_contains($lower, 'tanggal') || str_contains($lower, 'date')) {
        $colIndex['tanggal'] = $j;
      } elseif (str_contains($lower, 'keterangan') || str_contains($lower, 'remarks')) {
        $colIndex['keterangan'] = $j;
      } elseif (str_contains($lower, 'dana keluar') || str_contains($lower, 'outgoing')) {
        $colIndex['debit'] = $j;
      } elseif (str_contains($lower, 'dana masuk') || str_contains($lower, 'incoming')) {
        $colIndex['credit'] = $j;
      }
    }

    if ($colIndex['tanggal'] === null || $colIndex['keterangan'] === null) {
      throw new \Exception("Kolom Tanggal dan Keterangan wajib ditemukan.");
    }

    $transactions = [];
    $currentTransaction = null;
    $stopKeywords = ['total',
      'saldo akhir',
      'closing balance',
      'saldo awal',
      'initial balance',
      'dana masuk',
      'incoming transactions',
      'dana keluar',
      'outgoing transactions'];

    for ($i = $headerRowIndex + 2; $i < count($rows); $i++) {
      $row = $rows[$i];

      // Abaikan baris kosong
      if (empty(array_filter($row, fn($cell) => $cell !== null && $cell !== ''))) {
        continue;
      }

      // Deteksi baris ringkasan
      $firstCell = strtolower(trim($row[0] ?? ''));
      foreach ($stopKeywords as $kw) {
        if (str_contains($firstCell, $kw)) {
          break 2; // keluar dari loop utama
        }
      }

      // Cek apakah baris ini adalah awal transaksi baru (kolom pertama adalah angka)
      $isNewTransaction = is_numeric($row[0]);

      if ($isNewTransaction) {
        // Simpan transaksi sebelumnya jika ada
        if ($currentTransaction !== null) {
          $transactions[] = $currentTransaction;
        }

        // Ambil tanggal, nominal, dan deskripsi awal
        $dateStr = $row[$colIndex['tanggal']] ?? null;
        $descParts = [];
        if (isset($colIndex['keterangan'])) {
          $descParts[] = $row[$colIndex['keterangan']] ?? '';
        }
        $debitAmount = $row[$colIndex['debit']] ?? null;
        $creditAmount = $row[$colIndex['credit']] ?? null;

        $currentTransaction = [
          'date' => $dateStr,
          'description_parts' => $descParts,
          'debit' => $debitAmount,
          'credit' => $creditAmount,
        ];
      } else {
        // Lanjutan deskripsi transaksi sebelumnya
        if ($currentTransaction !== null && isset($colIndex['keterangan'])) {
          $currentTransaction['description_parts'][] = $row[$colIndex['keterangan']] ?? '';
        }
      }
    }

    // Simpan transaksi terakhir
    if ($currentTransaction !== null) {
      $transactions[] = $currentTransaction;
    }

    // Format hasil akhir
    $result = [];
    foreach ($transactions as $trx) {
      $dateStr = $trx['date'];
      $description = implode(' ', array_filter($trx['description_parts'], fn($s) => !empty(trim($s))));
      $debit = $trx['debit'];
      $credit = $trx['credit'];

      if (empty($dateStr) || (empty($debit) && empty($credit))) {
        continue;
      }

      $date = $this->normalizeDate($dateStr);
      if (!$date) {
        continue;
      }

      if (!empty($debit)) {
        $amount = $this->parseAmount($debit);
        $type = StatementType::DEBIT;
      } else {
        $amount = $this->parseAmount($credit);
        $type = StatementType::CREDIT;
      }

      $result[] = [
        'date' => $date,
        'description' => trim($description),
        'amount' => $amount,
        'type' => $type,
      ];
    }

    return $result;
  }

  private function normalizeDate(string $date): ?string
  {
    try {
      $formats = ['d/m/Y',
        'Y-m-d',
        'd-m-Y',
        'm/d/Y',
        'd M Y'];
      foreach ($formats as $format) {
        $carbon = Carbon::createFromFormat($format, trim($date));
        if ($carbon) {
          return $carbon->toDateString();
        }
      }
      return Carbon::parse(trim($date))->toDateString();
    } catch (\Exception $e) {
      return null;
    }
  }
}