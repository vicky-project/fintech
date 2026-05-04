<?php

namespace Modules\FinTech\Services\Parsers;

use Carbon\Carbon;
use Modules\FinTech\Enums\StatementType;

class MandiriCsvParser extends AbstractBankParser
{
  protected string $bankCode = 'mandiri';

  public function canParse(string $filePath, ?string $content = null): bool
  {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
      return false;
    }

    // Baca beberapa baris pertama untuk deteksi header
    $handle = fopen($filePath, 'r');
    if (!$handle) return false;

    $found = false;
    $count = 0;
    while (($row = fgetcsv($handle, 0, ',')) !== false && $count < 50) {
      if ($this->isHeaderRow($row)) {
        $found = true;
        break;
      }
      $count++;
    }
    fclose($handle);
    return $found;
  }

  public function parse(string $filePath): array
  {
    $transactions = [];
    $headerIndices = null;
    $inTransactionBlock = false;
    $stopKeywords = ['total pendapatan',
      'total pengeluaran',
      'saldo bulanan',
      'total pemasukan'];
    $rowCount = 0;
    $maxRowsPerMonth = 5000; // Batas aman per bulan

    foreach ($this->streamCsv($filePath) as $row) {
      $rowCount++;

      // Abaikan baris kosong
      if (empty(array_filter($row, fn($cell) => trim($cell) !== ''))) {
        continue;
      }

      // Deteksi header kolom
      if ($this->isHeaderRow($row)) {
        $headerIndices = $this->findHeaderIndices($row);
        $inTransactionBlock = true;
        $rowCount = 0; // Reset counter per bulan
        continue;
      }

      // Deteksi awal bulan baru (contoh: "BULAN: NOVEMBER 2025")
      $firstCell = strtolower(trim($row[0] ?? ''));
      if (str_starts_with($firstCell, 'bulan:')) {
        $inTransactionBlock = false;
        $headerIndices = null;
        continue;
      }

      // Deteksi baris ringkasan → hentikan blok transaksi
      $isSummary = false;
      foreach ($stopKeywords as $kw) {
        if (str_contains($firstCell, $kw)) {
          $isSummary = true;
          break;
        }
      }
      if ($isSummary) {
        $inTransactionBlock = false;
        continue;
      }

      // Jika tidak dalam blok transaksi, lewati
      if (!$inTransactionBlock || !$headerIndices) {
        continue;
      }

      // Batasi jumlah baris per bulan untuk mencegah loop tak terbatas
      if ($rowCount > $maxRowsPerMonth) {
        $inTransactionBlock = false;
        continue;
      }

      // Pastikan baris memiliki cukup kolom
      if (count($row) < 5) {
        continue;
      }

      $dateStr = $row[$headerIndices['tanggal']] ?? '';
      $typeStr = $row[$headerIndices['jenis']] ?? '';
      $description = $row[$headerIndices['deskripsi']] ?? '';
      $amountStr = $row[$headerIndices['jumlah']] ?? '';

      if (empty($dateStr) || empty($amountStr)) {
        continue;
      }

      $date = $this->normalizeDate($dateStr);
      if (!$date) {
        continue;
      }

      $amount = $this->parseAmount($amountStr);
      $type = $this->determineType($typeStr, $amountStr);

      $transactions[] = [
        'date' => $date,
        'description' => trim($description),
        'amount' => $amount,
        'type' => $type,
      ];
    }

    return $transactions;
  }

  /**
  * Cek apakah baris adalah header kolom.
  */
  private function isHeaderRow(array $row): bool
  {
    if (count($row) < 5) {
      return false;
    }
    $cells = array_map('strtolower', array_slice($row, 0, 5));
    return str_contains($cells[0], 'tanggal') &&
    str_contains($cells[1], 'jenis') &&
    str_contains($cells[2], 'kategori') &&
    str_contains($cells[3], 'deskripsi') &&
    str_contains($cells[4], 'jumlah');
  }

  /**
  * Cari indeks kolom dari header.
  */
  private function findHeaderIndices(array $row): ?array
  {
    $indices = [];
    foreach ($row as $i => $cell) {
      $cellLower = strtolower(trim($cell));
      if (str_contains($cellLower, 'tanggal')) {
        $indices['tanggal'] = $i;
      } elseif (str_contains($cellLower, 'jenis')) {
        $indices['jenis'] = $i;
      } elseif (str_contains($cellLower, 'kategori')) {
        $indices['kategori'] = $i;
      } elseif (str_contains($cellLower, 'deskripsi')) {
        $indices['deskripsi'] = $i;
      } elseif (str_contains($cellLower, 'jumlah')) {
        $indices['jumlah'] = $i;
      }
    }
    return isset($indices['tanggal'], $indices['deskripsi'], $indices['jumlah'])
    ? $indices
    : null;
  }

  /**
  * Normalisasi tanggal dari format "DD/MM/YYYY HH:MM:SS" atau "DD/MM/YYYY".
  */
  private function normalizeDate(string $dateStr): ?string
  {
    $datePart = explode(' ', trim($dateStr))[0];
    try {
      return Carbon::createFromFormat('d/m/Y', $datePart)->toDateString();
    } catch (\Exception $e) {
      return null;
    }
  }

  /**
  * Tentukan tipe dari kolom "Jenis" atau fallback.
  */
  private function determineType(string $typeStr, string $amountStr): StatementType
  {
    $lower = strtolower(trim($typeStr));
    if (in_array($lower, ['income', 'pemasukan', 'masuk'])) {
      return StatementType::CREDIT;
    }
    if (in_array($lower, ['expense', 'pengeluaran', 'keluar'])) {
      return StatementType::DEBIT;
    }
    // Fallback
    return StatementType::DEBIT;
  }

  /**
  * Membersihkan format "Rp 500.000" menjadi float.
  */
  protected function parseAmount(string $amount): float
  {
    $amount = preg_replace('/[^0-9,.-]/', '', $amount);
    return parent::parseAmount($amount);
  }
}