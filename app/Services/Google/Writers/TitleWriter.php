<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\SheetCursor;

class TitleWriter
{
  public function __construct(
    protected ValueWriter $valueWriter,
    protected StyleBuilder $styleBuilder
  ) {}

  public function writeTitle(
    string $sheetName,
    string $title,
    SheetCursor $cursor,
    int $colCount,
    int $sheetId
  ): void {
    $range = $sheetName . '!A' . $cursor->row . ':' . chr(64 + $colCount) . $cursor->row;
    $this->valueWriter->queue($range, [[$title]]);

    $this->styleBuilder->addRequest(new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => $colCount,
        ],
        'mergeType' => 'MERGE_ALL',
      ],
    ]));
    $this->styleBuilder->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => $colCount,
        ],
        'cell' => ['userEnteredFormat' => [
          'textFormat' => ['bold' => true, 'fontSize' => 14],
          'horizontalAlignment' => 'CENTER',
        ]],
        'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
      ],
    ]));
    $cursor->advanceRow();
  }

  public function writeSimpleTitle(
    string $sheetName,
    string $title,
    SheetCursor $cursor,
    int $colCount,
    int $sheetId
  ): void {
    $range = $sheetName . '!' . $cursor->getColLetter() . $cursor->row . ':' .
    chr(65 + $cursor->col + $colCount - 1) . $cursor->row;
    $this->valueWriter->queue($range, [[$title]]);

    $this->styleBuilder->addRequest(new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => $cursor->col,
          'endColumnIndex' => $cursor->col + $colCount,
        ],
        'mergeType' => 'MERGE_ALL',
      ],
    ]));
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
          'textFormat' => ['bold' => true, 'fontSize' => 11],
          'horizontalAlignment' => 'CENTER',
        ]],
        'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
      ],
    ]));
    $cursor->advanceRow();
  }
}