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

    // Cari header untuk menentukan indeks kolom
    $headerIndices = $this->findHeaderIndices($rows);
    if (!$headerIndices) {
      throw new \Exception("Format Excel/CSV Bank Mandiri tidak dikenali. Pastikan file memiliki kolom Tanggal, Keterangan, Debit, Kredit, atau Saldo.");
    }

    $transactions = [];
    $foundHeader = false;

    foreach ($rows as $row) {
      // Lewati sampai header ditemukan
      if (!$foundHeader) {
        if ($this->isHeaderRow($row, $headerIndices)) {
          $foundHeader = true;
        }
        continue;
      }

      // Abaikan baris kosong
      if (empty(array_filter($row))) {
        continue;
      }

      $date = $row[$headerIndices['tanggal']] ?? null;
      $description = $row[$headerIndices['keterangan']] ?? '';
      $debit = $row[$headerIndices['debit']] ?? null;
      $credit = $row[$headerIndices['kredit']] ?? null;

      // Abaikan jika tidak ada tanggal atau nominal
      if (!$date || (!$debit && !$credit)) {
        continue;
      }

      $dateStr = $this->normalizeDate($date);
      if (!$dateStr) {
        continue;
      }

      // Tentukan nominal dan tipe (debit = expense, kredit = income)
      if (!empty($debit) && is_numeric($this->parseAmount($debit))) {
        $amount = $this->parseAmount($debit);
        $type = StatementType::DEBIT;
      } elseif (!empty($credit) && is_numeric($this->parseAmount($credit))) {
        $amount = $this->parseAmount($credit);
        $type = StatementType::CREDIT;
      } else {
        continue;
      }

      $transactions[] = [
        'date' => $dateStr,
        'description' => trim($description),
        'amount' => $amount,
        'type' => $type,
      ];
    }

    return $transactions;
  }

  /**
  * Mencari indeks kolom berdasarkan header.
  */
  private function findHeaderIndices(array $rows): ?array
  {
    foreach ($rows as $row) {
      $indices = [];
      foreach ($row as $i => $cell) {
        $cellLower = strtolower(trim($cell));

        if (str_contains($cellLower, 'tanggal') || str_contains($cellLower, 'date')) {
          $indices['tanggal'] = $i;
        }
        if (str_contains($cellLower, 'keterangan') || str_contains($cellLower, 'deskripsi') || str_contains($cellLower, 'description') || str_contains($cellLower, 'remarks')) {
          $indices['keterangan'] = $i;
        }
        if (str_contains($cellLower, 'debit') || str_contains($cellLower, 'debet')) {
          $indices['debit'] = $i;
        }
        if (str_contains($cellLower, 'kredit') || str_contains($cellLower, 'credit')) {
          $indices['kredit'] = $i;
        }
      }

      if (isset($indices['tanggal'], $indices['keterangan']) && (isset($indices['debit']) || isset($indices['kredit']))) {
        return $indices;
      }
    }

    return null;
  }

  /**
  * Cek apakah baris ini adalah header.
  */
  private function isHeaderRow(array $row, array $headerIndices): bool
  {
    foreach ($headerIndices as $type => $index) {
      if (!isset($row[$index])) {
        return false;
      }
      $cell = strtolower(trim($row[$index]));
      if ($type === 'tanggal' && !str_contains($cell, 'tanggal') && !str_contains($cell, 'date')) {
        return false;
      }
      if ($type === 'keterangan' && !str_contains($cell, 'keterangan') && !str_contains($cell, 'deskripsi') && !str_contains($cell, 'description')) {
        return false;
      }
    }
    return true;
  }

  /**
  * Normalisasi format tanggal.
  */
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