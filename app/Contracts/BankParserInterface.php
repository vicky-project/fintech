<?php

namespace Modules\FinTech\Contracts;

interface BankParserInterface
{
  /**
  * Parse file dan kembalikan array transaksi.
  *
  * @param string $filePath
  * @return array [
  *   ['date' => '2024-01-01', 'description' => '...', 'amount' => 50000.00, 'type' => 'expense']
  * ]
  */
  public function parse(string $filePath): array;

  /**
  * Deteksi apakah file ini cocok dengan parser ini.
  */
  public function canParse(string $filePath, ?string $content = null): bool;

  /**
  * Kode bank (bca, mandiri, dll).
  */
  public function getBankCode(): string;
}