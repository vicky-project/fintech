<?php

namespace Modules\FinTech\Services\Google\Writers;

use Modules\FinTech\Services\Google\SheetCursor;

class DataWriter
{
  public function __construct(protected ValueWriter $valueWriter) {}

  public function writeData(string $sheetName, array $values, SheetCursor $cursor): int
  {
    if (empty($values)) return 0;

    $startColLetter = $cursor->getColLetter();
    $range = $sheetName . '!' . $startColLetter . $cursor->row;
    $this->valueWriter->queue($range, $values);

    $endRow = $cursor->row + count($values) - 1;
    $cursor->row = $endRow + 1;
    return $endRow;
  }
}