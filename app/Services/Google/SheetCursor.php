<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Exports\ChartDataProcessor;

class SheetCursor
{
  public int $row = 1;
  public int $col = 0;

  public function advanceRow(int $count = 1): void
  {
    $this->row += $count;
    $this->col = 0;
  }

  public function setCol(int $col): void
  {
    $this->col = $col;
  }

  public function getColLetter(): string
  {
    return chr(65 + $this->col);
  }
}