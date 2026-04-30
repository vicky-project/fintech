<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\GoogleSheetsClient;
use Modules\FinTech\Services\Google\SpreadsheetManager;

class SheetWriter
{
  protected GoogleSheetsClient $client;
  protected SpreadsheetManager $manager;

  public function __construct(GoogleSheetsClient $client, SpreadsheetManager $manager) {
    $this->client = $client;
    $this->manager = $manager;
  }

  public function writeMetadata(string $spreadsheetId, string $sheetName, array $metadata, SheetCursor $cursor): void
  {
    if (empty($metadata)) return;

    $rows = array_map(fn($line) => [$line], $metadata);
    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $cursor->row,
      new ValueRange(['values' => $rows]),
      ['valueInputOption' => 'RAW']
    );

    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $requests = [];
    foreach (range(0, count($metadata) - 1) as $i) {
      $requests[] = new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1 + $i,
            'endRowIndex' => $cursor->row + $i,
            'startColumnIndex' => 0,
            'endColumnIndex' => 7,
          ],
          'mergeType' => 'MERGE_ALL',
        ]
      ]);
    }
    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }

    $cursor->advanceRow(count($metadata) + 1);
  }

  public function writeHeaders(string $spreadsheetId, string $sheetName, array $headers, SheetCursor $cursor, ?string $dataType): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $colCount = count($headers);

    if ($dataType === 'transactions') {
      $row1 = ['Tanggal',
        'Tipe',
        'Kategori',
        'Dompet',
        'Amount',
        '',
        'Deskripsi'];
      $row2 = ['',
        '',
        '',
        '',
        'Pemasukan',
        'Pengeluaran',
        ''];

      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $cursor->row,
        new ValueRange(['values' => [$row1, $row2]]),
        ['valueInputOption' => 'RAW']
      );

      $requests = [];
      $colsToMergeVertically = [0,
        1,
        2,
        3,
        6];
      foreach ($colsToMergeVertically as $col) {
        $requests[] = $this->createMergeRequest($sheetId, $cursor->row, $cursor->row + 1, $col, $col + 1);
      }

      $requests[] = new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1,
            'endRowIndex' => $cursor->row,
            'startColumnIndex' => 4,
            'endColumnIndex' => 6,
          ],
          'mergeType' => 'MERGE_ALL'
        ]
      ]);

      $requests[] = new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1,
            'endRowIndex' => $cursor->row + 1,
            'startColumnIndex' => 0,
            'endColumnIndex' => 7,
          ],
          'cell' => [
            'userEnteredFormat' => [
              'horizontalAlignment' => 'CENTER',
              'verticalAlignment' => 'MIDDLE',
              'textFormat' => ['bold' => true]
            ]
          ],
          'fields' => 'userEnteredFormat(horizontalAlignment,verticalAlignment,textFormat)'
        ]
      ]);

      $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);

      $cursor->advanceRow(2);
    } elseif ($dataType === 'other') {
      $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);
    } else {
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $cursor->row,
        new ValueRange(['values' => [$headers]]),
        ['valueInputOption' => 'RAW']
      );
      $this->applyBoldCenter($spreadsheetId, $sheetId, $cursor->row, count($headers));
      $cursor->advanceRow();
    }
  }

  public function writeSimpleHeader(string $spreadsheetId, string $sheetName, array $headers, SheetCursor $cursor): void
  {
    $colCount = count($headers);
    $range = $sheetName . '!A' . $cursor->row . ':' . chr(64 + $colCount) . $cursor->row;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $range,
      new ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $this->applyHeaderStyle($spreadsheetId, $sheetId, $cursor->row, $colCount);
    $cursor->advanceRow();
  }

  public function writeData(string $spreadsheetId, string $sheetName, array $values, SheetCursor $cursor): int
  {
    if (empty($values)) return 0;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $cursor->row,
      new ValueRange(['values' => $values]),
      ['valueInputOption' => 'RAW']
    );

    $endRow = $cursor->row + count($values) - 1;
    $cursor->row = $endRow + 1;
    $cursor->col = 0;
    return $endRow;
  }

  public function writeSubtotal(string $spreadsheetId, string $sheetName, array $summary, ?string $dataType, SheetCursor $cursor, array $headers): void
  {
    $colCount = count($headers);
    $emptyRow = array_fill(0, $colCount, '');

    if ($dataType === 'transactions') {
      $labelRow = $emptyRow;
      $labelRow[0] = 'SUBTOTAL';
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $cursor->row,
        new ValueRange(['values' => [$labelRow]]),
        ['valueInputOption' => 'RAW']
      );
      $cursor->advanceRow();

      $metrics = [
        'Pemasukan: ' . ($summary['total_income'] ?? 0),
        'Pengeluaran: ' . ($summary['total_expense'] ?? 0),
        'Net: ' . ($summary['net'] ?? 0),
      ];
      foreach ($metrics as $text) {
        $detailRow = $emptyRow;
        $detailRow[0] = $text;
        $this->client->getSheetsService()->spreadsheets_values->update(
          $spreadsheetId,
          $sheetName . '!A' . $cursor->row,
          new ValueRange(['values' => [$detailRow]]),
          ['valueInputOption' => 'RAW']
        );
        $cursor->advanceRow();
      }
    } elseif ($dataType === 'transfers') {
      $row1 = $emptyRow; $row1[0] = 'SUBTOTAL';
      $row2 = $emptyRow; $row2[3] = 'Total Transfer: ' . ($summary['total'] ?? 0);
      $rows = [$row1,
        $row2];

      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $cursor->row,
        new ValueRange(['values' => $rows]),
        ['valueInputOption' => 'RAW']
      );
      $cursor->advanceRow(2);
    } elseif ($dataType === 'budgets') {
      $row1 = $emptyRow; $row1[0] = 'SUBTOTAL';
      $row2 = $emptyRow;
      $row2[3] = 'Total Limit: ' . ($summary['total_limit'] ?? 0);
      $row2[4] = 'Total Pengeluaran: ' . ($summary['total_spent'] ?? 0);
      $row2[6] = 'Sisa: ' . ($summary['remaining'] ?? 0);
      $rows = [$row1,
        $row2];

      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $cursor->row,
        new ValueRange(['values' => $rows]),
        ['valueInputOption' => 'RAW']
      );
      $cursor->advanceRow(2);
    }
  }

  public function writeFooter(string $spreadsheetId, string $sheetName, SheetCursor $cursor, array $headers): void
  {
    $text = 'Generated by ' . config('app.name', 'Laravel') . ' App - ' . now()->format('d M Y H:i');
    $emptyRow = array_fill(0, count($headers), '');
    $emptyRow[0] = $text;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $cursor->row,
      new ValueRange(['values' => [$emptyRow]]),
      ['valueInputOption' => 'RAW']
    );

    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $request = new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => count($headers),
        ],
        'mergeType' => 'MERGE_ALL',
      ]
    ]);

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);

    $cursor->advanceRow();
  }

  public function clearSheet(string $spreadsheetId, string $sheetName): void
  {
    $this->client->getSheetsService()->spreadsheets_values->clear(
      $spreadsheetId,
      $sheetName,
      new ClearValuesRequest()
    );
  }

  private function createMergeRequest(int $sheetId, int $startRow, int $endRow, int $startCol, int $endCol): \Google\Service\Sheets\Request
  {
    return new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $startRow - 1,
          'endRowIndex' => $endRow,
          'startColumnIndex' => $startCol,
          'endColumnIndex' => $endCol,
        ],
        'mergeType' => 'MERGE_ALL'
      ]
    ]);
  }

  private function applyBoldCenter(string $spreadsheetId, int $sheetId, int $row, int $colCount): void
  {
    $request = new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $row - 1,
          'endRowIndex' => $row,
          'startColumnIndex' => 0,
          'endColumnIndex' => $colCount,
        ],
        'cell' => [
          'userEnteredFormat' => [
            'horizontalAlignment' => 'CENTER',
            'textFormat' => ['bold' => true]
          ]
        ],
        'fields' => 'userEnteredFormat(horizontalAlignment,textFormat)'
      ]
    ]);
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  private function applyHeaderStyle(string $spreadsheetId, int $sheetId, int $row, int $colCount): void
  {
    $requests = [
      new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $row - 1,
            'endRowIndex' => $row,
            'startColumnIndex' => 0,
            'endColumnIndex' => $colCount,
          ],
          'cell' => [
            'userEnteredFormat' => [
              'backgroundColor' => ['red' => 79/255, 'green' => 129/255, 'blue' => 189/255],
              'textFormat' => ['foregroundColor' => ['red' => 1, 'green' => 1, 'blue' => 1], 'bold' => true, 'fontSize' => 11],
              'horizontalAlignment' => 'CENTER',
              'verticalAlignment' => 'MIDDLE',
            ],
          ],
          'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
        ],
      ]),
    ];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }
}