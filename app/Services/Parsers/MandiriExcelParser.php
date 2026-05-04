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
    \Log::debug("Result read spreadsheet:", ['data' => $rows]);

    // Cari baris header transaksi: mengandung "No" di kolom 0 dan "Tanggal" di kolom 4
    $headerRowIndex = null;
    foreach ($rows as $i => $row) {
      $col0 = strtolower(trim($row[1] ?? ''));
      $col4 = strtolower(trim($row[4] ?? ''));
      if ($col0 === 'no' && (str_contains($col4, 'tanggal') || str_contains($col4, 'date'))) {
        $headerRowIndex = $i;
        break;
      }
    }

    if ($headerRowIndex === null) {
      throw new \Exception("Format Excel Bank Mandiri tidak dikenali: header kolom tidak ditemukan.");
    }

    $transactions = [];
    $stopKeywords = [
      'total',
      'saldo akhir',
      'closing balance',
      'saldo awal',
      'initial balance'
    ];

    for ($i = $headerRowIndex + 1; $i < count($rows); $i++) {
      $row = $rows[$i];

      // Abaikan baris kosong
      $hasContent = false;
      foreach ($row as $cell) {
        if ($cell !== null && trim($cell) !== '') {
          $hasContent = true;
          break;
        }
      }
      if (!$hasContent) {
        continue;
      }

      // Deteksi baris ringkasan → berhenti
      $col0 = strtolower(trim($row[0] ?? ''));
      $col6 = strtolower(trim($row[6] ?? ''));
      $stopText = $col0 . ' ' . $col6;
      foreach ($stopKeywords as $kw) {
        if (str_contains($stopText, $kw)) {
          break 2;
        }
      }

      // Kolom 0 harus berupa angka (nomor urut transaksi)
      if (!is_numeric($row[0])) {
        continue;
      }

      $dateStr = trim($row[4] ?? '');
      $description = trim($row[7] ?? '');
      $creditStr = trim($row[15] ?? ''); // Dana Masuk
      $debitStr = trim($row[18] ?? ''); // Dana Keluar

      if (empty($dateStr) || (empty($debitStr) && empty($creditStr))) {
        continue;
      }

      $date = $this->normalizeDate($dateStr);
      if (!$date) {
        continue;
      }

      if (!empty($debitStr)) {
        $amount = $this->parseAmount($debitStr);
        $type = StatementType::DEBIT;
      } else {
        $amount = $this->parseAmount($creditStr);
        $type = StatementType::CREDIT;
      }

      $transactions[] = [
        'date' => $date,
        'description' => $description,
        'amount' => $amount,
        'type' => $type,
      ];
    }

    return $transactions;
  }

  private function normalizeDate(string $date): ?string
  {
    try {
      $formats = ['d M Y',
        'd/m/Y',
        'Y-m-d',
        'd-m-Y',
        'm/d/Y'];
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