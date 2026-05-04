<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\SheetCursor;

class MetadataWriter
{
  public function __construct(
    protected ValueWriter $valueWriter,
    protected StyleBuilder $styleBuilder
  ) {}

  public function writeMetadata(
    string $sheetName,
    array $metadata,
    SheetCursor $cursor,
    int $colCount,
    int $sheetId
  ): void {
    if (empty($metadata)) return;

    $rows = array_map(fn($line) => [$line], $metadata);
    $range = $sheetName . '!A' . $cursor->row;
    $this->valueWriter->queue($range, $rows);

    foreach (range(0, count($metadata) - 1) as $i) {
      $this->styleBuilder->addRequest(new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1 + $i,
            'endRowIndex' => $cursor->row + $i,
            'startColumnIndex' => 0,
            'endColumnIndex' => $colCount,
          ],
          'mergeType' => 'MERGE_ALL',
        ],
      ]));
    }
    $cursor->advanceRow(count($metadata) + 1);
  }
}