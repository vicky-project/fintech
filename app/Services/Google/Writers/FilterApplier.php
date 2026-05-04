<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;

class FilterApplier
{
  public function __construct(protected StyleBuilder $styleBuilder) {}

  public function applyBasicFilter(
    int $sheetId,
    int $headerStartRow,
    int $headerEndRow,
    int $startCol = 0,
    int $colCount = 7
  ): void {
    $this->styleBuilder->addRequest(new SheetsRequest([
      'setBasicFilter' => [
        'filter' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $headerStartRow - 1,
            'endRowIndex' => $headerEndRow,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $colCount,
          ],
        ],
      ],
    ]));
  }
}