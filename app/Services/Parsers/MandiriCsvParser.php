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
    if (!in_array($ext, ['csv'])) {
      return false;
    }
    // Baca beberapa baris pertama untuk deteksi
    $rows = $this->readCsv($filePath);
    if (empty($rows)) return false;

    // Deteksi keberadaan header "Tanggal", "Jenis", "Kategori", "Deskripsi", "Jumlah"
    foreach ($rows as $row) {
      if (count($row) >= 5 &&
        str_contains(strtolower($row[0] ?? ''), 'tanggal') &&
        str_contains(strtolower($row[1] ?? ''), 'jenis') &&
        str_contains(strtolower($row[3] ?? ''), 'deskripsi') &&
        str_contains(strtolower($row[4] ?? ''), 'jumlah')) {
        return true;
      }
    }
    return false;
  }

  public function parse(string $filePath): array
  {
    $rows = $this->readCsv($filePath);
    if (empty($rows)) {
      return [];
    }

    $transactions = [];
    $headerFound = false;
    $indices = null;

    foreach ($rows as $row) {
      // Abaikan baris kosong
      if (empty(array_filter($row))) continue;

      // Cari header
      if (!$headerFound) {
        $indices = $this->findHeaderIndices($row);
        if ($indices) {
          $headerFound = true;
        }
        continue;
      }

      // Hentikan jika menemui baris ringkasan
      if ($this->isSummaryRow($row)) {
        break;
      }

      // Pastikan baris memiliki data yang cukup
      if (count($row) < 5) continue;

      $dateStr = $row[$indices['tanggal']] ?? null;
      $typeStr = $row[$indices['jenis']] ?? null;
      $description = $row[$indices['deskripsi']] ?? '';
      $amountStr = $row[$indices['jumlah']] ?? '';

      if (!$dateStr || !$amountStr) continue;

      $date = $this->normalizeDate($dateStr);
      if (!$date) continue;

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
  * Mencari indeks kolom berdasarkan header.
  */
  private function findHeaderIndices(array $row): ?array
  {
    $indices = [];
    foreach ($row as $i => $cell) {
      $cellLower = strtolower(trim($cell));
      if (str_contains($cellLower, 'tanggal')) $indices['tanggal'] = $i;
      if (str_contains($cellLower, 'jenis')) $indices['jenis'] = $i;
      if (str_contains($cellLower, 'kategori')) $indices['kategori'] = $i;
      if (str_contains($cellLower, 'deskripsi')) $indices['deskripsi'] = $i;
      if (str_contains($cellLower, 'jumlah')) $indices['jumlah'] = $i;
    }
    return (isset($indices['tanggal'], $indices['deskripsi'], $indices['jumlah'])) ? $indices : null;
  }

  /**
  * Deteksi baris ringkasan untuk menghentikan parsing.
  */
  private function isSummaryRow(array $row): bool
  {
    $firstCell = strtolower(trim($row[0] ?? ''));
    $summaryKeywords = ['total pendapatan',
      'total pengeluaran',
      'saldo bulanan',
      'total pemasukan',
      'total'];
    foreach ($summaryKeywords as $kw) {
      if (str_contains($firstCell, $kw)) {
        return true;
      }
    }
    return false;
  }

  /**
  * Normalisasi format tanggal.
  */
  private function normalizeDate(string $dateStr): ?string
  {
    // Format: "06/11/2025 18:46:00" -> ambil bagian tanggal saja
    $datePart = explode(' ', trim($dateStr))[0];
    try {
      return Carbon::createFromFormat('d/m/Y', $datePart)->toDateString();
    } catch (\Exception $e) {
      return null;
    }
  }

  /**
  * Tentukan tipe transaksi dari kolom "Jenis" atau tanda pada jumlah.
  */
  private function determineType(?string $typeStr, string $amountStr): StatementType
  {
    $lower = strtolower($typeStr ?? '');
    if (str_contains($lower, 'income') || str_contains($lower, 'pemasukan') || str_contains($lower, 'masuk')) {
      return StatementType::CREDIT;
    }
    if (str_contains($lower, 'expense') || str_contains($lower, 'pengeluaran') || str_contains($lower, 'keluar')) {
      return StatementType::DEBIT;
    }
    // Fallback: deteksi dari tanda jumlah
    if (str_contains($amountStr, '-')) {
      return StatementType::DEBIT;
    }
    // Jika jumlah diawali "Rp " tanpa minus, anggap income? (tergantung konteks)
    // Di contoh, expense juga tidak ada minus. Maka default expense.
    return StatementType::DEBIT;
  }

  /**
  * Override parseAmount untuk membersihkan format "Rp 500.000"
  */
  protected function parseAmount(string $amount): float
  {
    // Hapus "Rp", spasi, dan karakter non-numerik kecuali koma/titik
    $amount = preg_replace('/[^0-9,.-]/', '', $amount);
    return parent::parseAmount($amount);
  }
}