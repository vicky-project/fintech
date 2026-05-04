<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\SheetCursor;

class HeaderWriter
{
  public function __construct(
    protected ValueWriter $valueWriter,
    protected StyleBuilder $styleBuilder
  ) {}

  public function writeSimpleHeader(
    string $sheetName,
    array $headers,
    SheetCursor $cursor,
    int $sheetId
  ): void {
    $colCount = count($headers);
    $range = $sheetName . '!' . $cursor->getColLetter() . $cursor->row . ':' .
    chr(65 + $cursor->col + $colCount - 1) . $cursor->row;
    $this->valueWriter->queue($range, [$headers]);

    $this->styleBuilder->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => $cursor->col,
          'endColumnIndex' => $cursor->col + $colCount,
        ],
        'cell' => ['userEnteredFormat' => [
          'backgroundColor' => ['red' => 79/255, 'green' => 129/255, 'blue' => 189/255],
          'textFormat' => [
            'foregroundColor' => ['red' => 1, 'green' => 1, 'blue' => 1],
            'bold' => true,
            'fontSize' => 11,
          ],
          'horizontalAlignment' => 'CENTER',
          'verticalAlignment' => 'MIDDLE',
        ]],
        'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
      ],
    ]));
    $cursor->advanceRow();
  }
}