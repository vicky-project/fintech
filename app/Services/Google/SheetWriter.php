<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Exports\ChartDataProcessor;

class SheetWriter
{
  protected GoogleSheetsClient $client;
  protected SpreadsheetManager $manager;

  public function __construct(GoogleSheetsClient $client, SpreadsheetManager $manager) {
    $this->client = $client;
    $this->manager = $manager;
  }

  // ======================== METADATA ========================
  public function writeMetadata(string $spreadsheetId, string $sheetName, array $metadata, SheetCursor $cursor, int $colCount = 7): void
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
            'endColumnIndex' => $colCount,
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

  // ======================== HEADER ========================
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
              'backgroundColor' => ['red' => 79/255, 'green' => 129/255, 'blue' => 189/255],
              'textFormat' => [
                'foregroundColor' => ['red' => 1, 'green' => 1, 'blue' => 1],
                'bold' => true,
                'fontSize' => 11
              ],
              'horizontalAlignment' => 'CENTER',
              'verticalAlignment' => 'MIDDLE',
            ]
          ],
          'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)'
        ]
      ]);

      $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);

      $cursor->advanceRow(2);
    } else {
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $cursor->row,
        new ValueRange(['values' => [$headers]]),
        ['valueInputOption' => 'RAW']
      );
      $this->applyBoldCenter($spreadsheetId, $sheetId, $cursor->row, $colCount);
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

  // ======================== DATA ========================
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

  // ======================== SUBTOTAL ========================
  public function writeSubtotal(string $spreadsheetId, string $sheetName, array $summary, ?string $dataType, SheetCursor $cursor, array $headers): void
  {
    $colCount = count($headers);
    $emptyRow = array_fill(0, $colCount, '');
    $fmt = fn($val) => ChartDataProcessor::formatCurrency($val, $summary);

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
        'Pemasukan: ' . $fmt($summary['total_income'] ?? 0),
        'Pengeluaran: ' . $fmt($summary['total_expense'] ?? 0),
        'Net: ' . $fmt($summary['net'] ?? 0),
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
      $row2 = $emptyRow; $row2[3] = 'Total Transfer: ' . $fmt($summary['total'] ?? 0);
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
      $row2[3] = 'Total Limit: ' . $fmt($summary['total_limit'] ?? 0);
      $row2[4] = 'Total Pengeluaran: ' . $fmt($summary['total_spent'] ?? 0);
      $row2[6] = 'Sisa: ' . $fmt($summary['remaining'] ?? 0);
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

  // ======================== FOOTER ========================
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

    $mergeRequest = new SheetsRequest([
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

    $styleRequest = new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => count($headers),
        ],
        'cell' => [
          'userEnteredFormat' => [
            'textFormat' => [
              'italic' => true,
              'foregroundColor' => ['red' => 136/255, 'green' => 136/255, 'blue' => 136/255],
              'fontSize' => 10,
            ],
            'horizontalAlignment' => 'CENTER',
          ],
        ],
        'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
      ],
    ]);

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$mergeRequest, $styleRequest]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);

    $cursor->advanceRow();
  }

  // ======================== CHART ========================
  public function writeTransactionChart(
    string $spreadsheetId,
    string $sheetName,
    int $dataStartRow,
    int $dataEndRow,
    int $chartRow
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);

    $chartRequest = new SheetsRequest([
      'addChart' => [
        'chart' => [
          'spec' => [
            'title' => 'Pemasukan vs Pengeluaran',
            'basicChart' => [
              'chartType' => 'COLUMN',
              'legendPosition' => 'BOTTOM_LEGEND',
              'axis' => [
                ['position' => 'BOTTOM_AXIS', 'title' => 'Tanggal'],
                ['position' => 'LEFT_AXIS', 'title' => 'Jumlah']
              ],
              'domains' => [
                [
                  'domain' => [
                    'sourceRange' => [
                      'sources' => [[
                        'sheetId' => $sheetId,
                        'startRowIndex' => $dataStartRow - 1,
                        'endRowIndex' => $dataEndRow,
                        'startColumnIndex' => 0,
                        'endColumnIndex' => 1,
                      ]]
                    ]
                  ]
                ]
              ],
              'series' => [
                [
                  'series' => [
                    'sourceRange' => [
                      'sources' => [[
                        'sheetId' => $sheetId,
                        'startRowIndex' => $dataStartRow - 1,
                        'endRowIndex' => $dataEndRow,
                        'startColumnIndex' => 4,
                        'endColumnIndex' => 5,
                      ]]
                    ]
                  ],
                  'targetAxis' => 'LEFT_AXIS'
                ],
                [
                  'series' => [
                    'sourceRange' => [
                      'sources' => [[
                        'sheetId' => $sheetId,
                        'startRowIndex' => $dataStartRow - 1,
                        'endRowIndex' => $dataEndRow,
                        'startColumnIndex' => 5,
                        'endColumnIndex' => 6,
                      ]]
                    ]
                  ],
                  'targetAxis' => 'LEFT_AXIS'
                ]
              ],
              'headerCount' => 1
            ]
          ],
          'position' => [
            'overlayPosition' => [
              'anchorCell' => [
                'sheetId' => $sheetId,
                'rowIndex' => $chartRow - 1,
                'columnIndex' => 0
              ],
              'widthPixels' => 600,
              'heightPixels' => 350
            ]
          ]
        ]
      ]
    ]);

    $batchUpdate = new BatchUpdateSpreadsheetRequest([
      'requests' => [$chartRequest]
    ]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  // ======================== HELPERS ========================
  private function createMergeRequest(int $sheetId, int $startRow, int $endRow, int $startCol, int $endCol): SheetsRequest
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

  public function clearSheet(string $spreadsheetId, string $sheetName): void
  {
    $this->client->getSheetsService()->spreadsheets_values->clear(
      $spreadsheetId,
      $sheetName,
      new ClearValuesRequest()
    );
  }

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

  // ======================== BORDER ========================
  public function applyBordersToRange(
    string $spreadsheetId,
    string $sheetName,
    int $startRow,
    int $endRow,
    int $startCol = 0,
    int $endCol = 0,
    array $headers = []
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || $endRow < $startRow) return;

    // Jika endCol tidak diisi, gunakan jumlah header (atau fallback ke 7)
    $colCount = $endCol > 0 ? $endCol : (count($headers) > 0 ? count($headers) : 7);

    $request = new SheetsRequest([
      'updateBorders' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $startRow - 1,
          'endRowIndex' => $endRow,
          'startColumnIndex' => $startCol,
          'endColumnIndex' => $colCount,
        ],
        'top' => ['style' => 'SOLID', 'width' => 1],
        'bottom' => ['style' => 'SOLID', 'width' => 1],
        'left' => ['style' => 'SOLID', 'width' => 1],
        'right' => ['style' => 'SOLID', 'width' => 1],
        'innerHorizontal' => ['style' => 'SOLID', 'width' => 1],
        'innerVertical' => ['style' => 'SOLID', 'width' => 1],
      ]
    ]);

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }

  // ======================== COLOR HELPERS ========================
  private function setCellColor(int $sheetId, int $row, int $col, array $color, bool $bold, array &$requests): void
  {
    $requests[] = new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $row - 1,
          'endRowIndex' => $row,
          'startColumnIndex' => $col,
          'endColumnIndex' => $col + 1,
        ],
        'cell' => [
          'userEnteredFormat' => [
            'textFormat' => [
              'foregroundColor' => $color,
              'bold' => $bold,
            ],
          ],
        ],
        'fields' => 'userEnteredFormat(textFormat)',
      ],
    ]);
  }

  public function applyTransactionColors(
    string $spreadsheetId,
    string $sheetName,
    array $values,
    int $dataStartRow,
    int $dataEndRow
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $requests = [];
    $colIncome = 4;
    $colExpense = 5;
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $black = ['red' => 0,
      'green' => 0,
      'blue' => 0];

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $tipe = $row[1] ?? '';

      if ($tipe === 'Pemasukan') {
        $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true, $requests);
        $this->setCellColor($sheetId, $rowNum, $colExpense, $black, false, $requests);
      } elseif ($tipe === 'Pengeluaran') {
        $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true, $requests);
        $this->setCellColor($sheetId, $rowNum, $colIncome, $black, false, $requests);
      }
    }

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  public function applySubtotalColors(
    string $spreadsheetId,
    string $sheetName,
    int $subStartRow,
    int $subEndRow,
    array $summary
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || $subEndRow < $subStartRow) return;

    $requests = [];
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $colA = 0;

    $this->setCellColor($sheetId, $subStartRow + 1, $colA, $green, true, $requests);
    $this->setCellColor($sheetId, $subStartRow + 2, $colA, $red, true, $requests);

    $netVal = ($summary['net'] ?? 0);
    $netColor = $netVal >= 0 ? $green : $red;
    $this->setCellColor($sheetId, $subStartRow + 3, $colA, $netColor, true, $requests);

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  public function applySummaryColors(
    string $spreadsheetId,
    string $sheetName,
    int $headerRow,
    array $values
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || empty($values)) return;

    $requests = [];
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $colIncome = 1;
    $colExpense = 2;
    $colNet = 3;
    $dataStartRow = $headerRow + 1;

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true, $requests);
      $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true, $requests);

      $netStr = $row[3] ?? '0';
      $netVal = (float) str_replace(['Rp', '.', ','], '', $netStr);
      $netColor = $netVal >= 0 ? $green : $red;
      $this->setCellColor($sheetId, $rowNum, $colNet, $netColor, true, $requests);
    }

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  public function applyTopSpendingColors(
    string $spreadsheetId,
    string $sheetName,
    int $headerRow,
    array $values
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || empty($values)) return;

    $requests = [];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $colJumlah = 2;
    $dataStartRow = $headerRow + 1;

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colJumlah, $red, true, $requests);
    }

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }
}