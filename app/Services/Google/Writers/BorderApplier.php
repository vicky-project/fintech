<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;

class BorderApplier
{
  public function __construct(protected StyleBuilder $styleBuilder) {}

  public function applyBordersToRange(
    int $sheetId,
    int $startRow,
    int $endRow,
    int $startCol = 0,
    int $endCol = 0,
    int $colCount = 0
  ): void {
    if ($endRow < $startRow) return;
    $colCount = $endCol > 0 ? $endCol : ($colCount > 0 ? $colCount : 7);

    $this->styleBuilder->addRequest(new SheetsRequest([
      'updateBorders' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $startRow - 1,
          'endRowIndex' => $endRow,
          'startColumnIndex' => $startCol,
          'endColumnIndex' => $startCol + $colCount,
        ],
        'top' => ['style' => 'SOLID', 'width' => 1],
        'bottom' => ['style' => 'SOLID', 'width' => 1],
        'left' => ['style' => 'SOLID', 'width' => 1],
        'right' => ['style' => 'SOLID', 'width' => 1],
        'innerHorizontal' => ['style' => 'SOLID', 'width' => 1],
        'innerVertical' => ['style' => 'SOLID', 'width' => 1],
      ],
    ]));
  }
}