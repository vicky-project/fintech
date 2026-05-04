<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;

class CurrencyFormatter
{
  public function __construct(protected StyleBuilder $styleBuilder) {}

  public function applyCurrencyFormat(
    int $sheetId,
    int $dataStartRow,
    int $dataEndRow,
    array $summary,
    int $startCol = 4,
    int $colCount = 2
  ): void {
    $symbol = $summary['symbol'] ?? 'Rp';
    $thousandsSep = $summary['thousands_separator'] ?? '.';
    $decimalMark = $summary['decimal_mark'] ?? ',';
    $precision = $summary['precision'] ?? 0;

    $pattern = $symbol . ' #' . $thousandsSep . '##0';
    if ($precision > 0) {
      $pattern .= $decimalMark . str_repeat('0', $precision);
    }

    $this->styleBuilder->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $dataStartRow - 1,
          'endRowIndex' => $dataEndRow,
          'startColumnIndex' => $startCol,
          'endColumnIndex' => $startCol + $colCount,
        ],
        'cell' => ['userEnteredFormat' => [
          'numberFormat' => [
            'type' => 'CURRENCY',
            'pattern' => $pattern,
          ],
        ]],
        'fields' => 'userEnteredFormat.numberFormat',
      ],
    ]));
  }
}