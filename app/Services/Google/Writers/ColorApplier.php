<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;

class ColorApplier
{
  public function __construct(protected StyleBuilder $styleBuilder) {}

  private function setCellColor(int $sheetId, int $row, int $col, array $color, bool $bold): void
  {
    $this->styleBuilder->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $row - 1,
          'endRowIndex' => $row,
          'startColumnIndex' => $col,
          'endColumnIndex' => $col + 1,
        ],
        'cell' => ['userEnteredFormat' => [
          'textFormat' => [
            'foregroundColor' => $color,
            'bold' => $bold,
          ],
        ]],
        'fields' => 'userEnteredFormat(textFormat)',
      ],
    ]));
  }

  public function applyTransactionColors(
    int $sheetId,
    array $values,
    int $dataStartRow,
    int $dataEndRow
  ): void {
    $colIncome = 4;
    $colExpense = 5;
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $black = ['red' => 0,
      'green' => 0,
      'blue' => 0];

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $tipe = $row[1] ?? '';
      if ($tipe === 'Pemasukan') {
        $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true);
        $this->setCellColor($sheetId, $rowNum, $colExpense, $black, false);
      } elseif ($tipe === 'Pengeluaran') {
        $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true);
        $this->setCellColor($sheetId, $rowNum, $colIncome, $black, false);
      }
    }
  }

  public function applySummaryColors(
    int $sheetId,
    int $headerRow,
    array $values,
    int $startCol = 0
  ): void {
    if (empty($values)) return;

    $colIncome = $startCol + 1;
    $colExpense = $startCol + 2;
    $colNet = $startCol + 3;
    $dataStartRow = $headerRow + 1;

    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true);
      $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true);
      $netVal = (float)($row[3] ?? 0);
      $netColor = $netVal >= 0 ? $green : $red;
      $this->setCellColor($sheetId, $rowNum, $colNet, $netColor, true);
    }
  }

  public function applyTopSpendingColors(
    int $sheetId,
    int $headerRow,
    array $values,
    int $startCol = 0
  ): void {
    if (empty($values)) return;

    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $colJumlah = $startCol + 2;
    $dataStartRow = $headerRow + 1;

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colJumlah, $red, true);
    }
  }

  /**
  * Metode tambahan untuk kategori expense table,
  * agar bisa mewarnai sel individual tanpa harus akses langsung.
  */
  public function setCellColorPublic(int $sheetId, int $row, int $col, array $color, bool $bold): void
  {
    $this->setCellColor($sheetId, $row, $col, $color, $bold);
  }
}