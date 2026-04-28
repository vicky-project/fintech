<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Services\Google\SpreadsheetManager;

class SheetStyler
{
  protected GoogleSheetsClient $client;
  protected SpreadsheetManager $manager;

  public function __construct(GoogleSheetsClient $client, SpreadsheetManager $manager) {
    $this->client = $client;
    $this->manager = $manager;
  }

  /**
  * Aplikasikan semua styling ke sheet.
  */
  public function apply(
    string $spreadsheetId,
    string $sheetName,
    int $headerStartRow,
    ?string $dataType,
    array $headers,
    array $values,
    int $dataStartRow,
    int $dataEndRow,
    int $subStartRow,
    int $subEndRow,
    int $footerRow
  ): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $colCount = count($headers);
    $requests = [];

    // Header styling
    $headerRows = ($dataType === 'transactions') ? 2 : 1;
    $requests[] = new SheetsRequest(['repeatCell' => [
      'range' => [
        'sheetId' => $sheetId,
        'startRowIndex' => $headerStartRow - 1,
        'endRowIndex' => $headerStartRow - 1 + $headerRows,
        'startColumnIndex' => 0,
        'endColumnIndex' => $colCount,
      ],
      'cell' => ['userEnteredFormat' => [
        'backgroundColor' => ['red' => 79/255, 'green' => 129/255, 'blue' => 189/255],
        'textFormat' => ['foregroundColor' => ['red' => 1, 'green' => 1, 'blue' => 1], 'bold' => true, 'fontSize' => 11],
        'horizontalAlignment' => 'CENTER',
        'verticalAlignment' => 'MIDDLE',
      ]],
      'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
    ]]);

    // Border data (termasuk header)
    $requests[] = new SheetsRequest(['updateBorders' => [
      'range' => [
        'sheetId' => $sheetId,
        'startRowIndex' => $headerStartRow - 1,
        'endRowIndex' => $dataEndRow,
        'startColumnIndex' => 0,
        'endColumnIndex' => $colCount,
      ],
      'top' => ['style' => 'SOLID', 'width' => 1],
      'bottom' => ['style' => 'SOLID', 'width' => 1],
      'left' => ['style' => 'SOLID', 'width' => 1],
      'right' => ['style' => 'SOLID', 'width' => 1],
      'innerHorizontal' => ['style' => 'SOLID', 'width' => 1],
      'innerVertical' => ['style' => 'SOLID', 'width' => 1],
    ]]);

    // Warna pemasukan/pengeluaran (transactions)
    if ($dataType === 'transactions') {
      $this->applyTransactionColors($requests, $sheetId, $values, $dataStartRow, $dataEndRow);
      // Rata kanan kolom E & F
      $requests[] = new SheetsRequest(['repeatCell' => [
        'range' => ['sheetId' => $sheetId, 'startRowIndex' => $dataStartRow-1, 'endRowIndex' => $dataEndRow, 'startColumnIndex' => 4, 'endColumnIndex' => 6],
        'cell' => ['userEnteredFormat' => ['horizontalAlignment' => 'RIGHT']],
        'fields' => 'userEnteredFormat(horizontalAlignment)',
      ]]);
    } elseif ($dataType === 'transfers') {
      $requests[] = new SheetsRequest(['repeatCell' => [
        'range' => ['sheetId' => $sheetId, 'startRowIndex' => $dataStartRow-1, 'endRowIndex' => $dataEndRow, 'startColumnIndex' => 3, 'endColumnIndex' => 4],
        'cell' => ['userEnteredFormat' => ['horizontalAlignment' => 'RIGHT']],
        'fields' => 'userEnteredFormat(horizontalAlignment)',
      ]]);
    } elseif ($dataType === 'budgets') {
      $requests[] = new SheetsRequest(['repeatCell' => [
        'range' => ['sheetId' => $sheetId, 'startRowIndex' => $dataStartRow-1, 'endRowIndex' => $dataEndRow, 'startColumnIndex' => 3, 'endColumnIndex' => 5],
        'cell' => ['userEnteredFormat' => ['horizontalAlignment' => 'RIGHT']],
        'fields' => 'userEnteredFormat(horizontalAlignment)',
      ]]);
    }

    // Subtotal styling
    if ($subEndRow >= $subStartRow) {
      $requests[] = new SheetsRequest(['repeatCell' => [
        'range' => ['sheetId' => $sheetId, 'startRowIndex' => $subStartRow-1, 'endRowIndex' => $subEndRow, 'startColumnIndex' => 0, 'endColumnIndex' => $colCount],
        'cell' => ['userEnteredFormat' => [
          'backgroundColor' => ['red' => 217/255, 'green' => 226/255, 'blue' => 243/255],
          'textFormat' => ['bold' => true, 'fontSize' => 11],
          'borders' => [
            'top' => ['style' => 'SOLID', 'width' => 1],
            'bottom' => ['style' => 'SOLID', 'width' => 1],
            'left' => ['style' => 'SOLID', 'width' => 1],
            'right' => ['style' => 'SOLID', 'width' => 1],
          ],
        ]],
        'fields' => 'userEnteredFormat(backgroundColor,textFormat,borders)',
      ]]);
    }

    // Footer styling
    $requests[] = new SheetsRequest(['repeatCell' => [
      'range' => ['sheetId' => $sheetId, 'startRowIndex' => $footerRow-1, 'endRowIndex' => $footerRow, 'startColumnIndex' => 0, 'endColumnIndex' => $colCount],
      'cell' => ['userEnteredFormat' => [
        'textFormat' => ['italic' => true, 'foregroundColor' => ['red' => 136/255, 'green' => 136/255, 'blue' => 136/255], 'fontSize' => 10],
        'horizontalAlignment' => 'CENTER',
      ]],
      'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
    ]]);

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  protected function applyTransactionColors(array &$requests, int $sheetId, array $values, int $dataStartRow, int $dataEndRow): void
  {
    $colE = 4; $colF = 5;
    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $tipe = $row[1] ?? '';
      if ($tipe === 'Pemasukan') {
        $requests[] = new SheetsRequest(['repeatCell' => [
          'range' => ['sheetId' => $sheetId, 'startRowIndex' => $rowNum-1, 'endRowIndex' => $rowNum, 'startColumnIndex' => $colE, 'endColumnIndex' => $colE+1],
          'cell' => ['userEnteredFormat' => ['textFormat' => ['foregroundColor' => ['red' => 40/255, 'green' => 167/255, 'blue' => 69/255], 'bold' => true]]],
          'fields' => 'userEnteredFormat(textFormat)',
        ]]);
      } elseif ($tipe === 'Pengeluaran') {
        $requests[] = new SheetsRequest(['repeatCell' => [
          'range' => ['sheetId' => $sheetId, 'startRowIndex' => $rowNum-1, 'endRowIndex' => $rowNum, 'startColumnIndex' => $colF, 'endColumnIndex' => $colF+1],
          'cell' => ['userEnteredFormat' => ['textFormat' => ['foregroundColor' => ['red' => 220/255, 'green' => 53/255, 'blue' => 69/255], 'bold' => true]]],
          'fields' => 'userEnteredFormat(textFormat)',
        ]]);
      }
    }
  }

  /**
  * Auto-resize kolom.
  */
  public function autoResizeColumns(string $spreadsheetId, string $sheetName, int $columnCount): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $requests = [new SheetsRequest([
      'autoResizeDimensions' => [
        'dimensions' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => 0,
          'endIndex' => $columnCount,
        ]
      ]
    ])];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }
}