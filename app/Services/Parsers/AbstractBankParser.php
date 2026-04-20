<?php

namespace Modules\FinTech\Services\Parsers;

use Modules\FinTech\Contracts\BankParserInterface;

abstract class AbstractBankParser implements BankParserInterface
{
  protected string $bankCode;

  public function getBankCode(): string
  {
    return $this->bankCode;
  }

  /**
  * Helper untuk membaca file Excel/CSV menggunakan Maatwebsite.
  */
  protected function readSpreadsheet(string $filePath): array
  {
    $spreadsheet = \Maatwebsite\Excel\Facades\Excel::toArray([], $filePath);
    return $spreadsheet[0] ?? [];
  }

  /**
  * Helper untuk membersihkan nominal (hapus titik, koma, dll).
  */
  protected function parseAmount(string $amount): float
  {
    $amount = preg_replace('/[^0-9,.-]/', '', $amount);
    $amount = str_replace('.', '', $amount);
    $amount = str_replace(',', '.', $amount);
    return (float) $amount;
  }
}