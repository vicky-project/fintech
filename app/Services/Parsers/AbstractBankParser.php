<?php

namespace Modules\FinTech\Services\Parsers;

use Modules\FinTech\Contracts\BankParserInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

abstract class AbstractBankParser implements BankParserInterface
{
  /**
  * Kode bank (di-set oleh subclass).
  */
  protected string $bankCode;

  /**
  * Currency
  */
  protected string $currency = 'IDR';

  /**
  * Mendapatkan kode bank.
  */
  public function getBankCode(): string
  {
    return $this->bankCode;
  }

  public function getCurrency(): string
  {
    return $this->currency;
  }

  /**
  * Membaca file spreadsheet (CSV, XLS, XLSX) menjadi array.
  *
  * @param string $filePath
  * @return array
  */
  protected function readSpreadsheet(string $filePath): array
  {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($extension === 'csv') {
      return $this->readCsv($filePath);
    }

    // Untuk Excel, gunakan Maatwebsite
    $spreadsheet = \Maatwebsite\Excel\Facades\Excel::toArray([], $filePath);
    return $spreadsheet[0] ?? [];
  }

  /**
  * Membaca file CSV menjadi array.
  *
  * @param string $filePath
  * @return array
  */
  protected function readCsv(string $filePath): array
  {
    $rows = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
      while (($data = fgetcsv($handle, 1000, ',')) !== false) {
        $rows[] = $data;
      }
      fclose($handle);
    }
    return $rows;
  }

  /**
  * Membaca file CSV baris per baris menggunakan generator (streaming).
  */
  protected function streamCsv(string $filePath): \Generator
  {
    $handle = fopen($filePath, 'r');
    if (!$handle) {
      throw new \Exception("Tidak dapat membuka file: {$filePath}");
    }
    while (($data = fgetcsv($handle, 0, ',')) !== false) {
      yield $data;
    }
    fclose($handle);
  }

  /**
  * Membersihkan string nominal menjadi float.
  * Contoh: "1,234,567.89" -> 1234567.89
  *         "1.234.567,89" -> 1234567.89
  *
  * @param string $amount
  * @return float
  */
  protected function parseAmount(string $amount): float
  {
    // Hapus semua karakter kecuali angka, koma, titik, dan minus
    $amount = preg_replace('/[^0-9,.-]/', '', $amount);

    // Deteksi format: jika ada koma diikuti dua digit di akhir, itu desimal
    if (preg_match('/,\d{2}$/', $amount)) {
      // Format Indonesia: 1.234.567,89
      $amount = str_replace('.', '', $amount);
      $amount = str_replace(',', '.', $amount);
    } elseif (preg_match('/\.\d{2}$/', $amount)) {
      // Format Inggris: 1,234,567.89
      $amount = str_replace(',', '', $amount);
    } else {
      // Tidak ada desimal
      $amount = str_replace([',', '.'], '', $amount);
    }

    return (float) $amount;
  }

  /**
  * Mengecek apakah string mengandung keyword tertentu.
  *
  * @param string $haystack
  * @param array $needles
  * @return bool
  */
  protected function containsAny(string $haystack, array $needles): bool
  {
    $lower = strtolower($haystack);
    foreach ($needles as $needle) {
      if (str_contains($lower, strtolower($needle))) {
        return true;
      }
    }
    return false;
  }

  /**
  * Mengekstrak teks dari file PDF.
  *
  * @param string $filePath
  * @return string
  */
  protected function extractPdfText(string $filePath): string
  {
    $parser = new \Smalot\PdfParser\Parser();
    $pdf = $parser->parseFile($filePath);
    return $pdf->getText();
  }
}