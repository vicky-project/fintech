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

  /**
  * Tulis metadata (informasi di atas tabel).
  */
  public function writeMetadata(string $spreadsheetId, string $sheetName, array $metadata, int &$currentRow): void
  {
    if (empty($metadata)) return;

    $rows = array_map(fn($line) => [$line], $metadata);
    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $currentRow,
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
            'startRowIndex' => $currentRow - 1 + $i,
            'endRowIndex' => $currentRow + $i,
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

    $currentRow += count($metadata) + 1; // +1 baris kosong
  }

  /**
  * Tulis header tabel (mendukung dua baris untuk transaksi).
  */
  public function writeHeaders(string $spreadsheetId, string $sheetName, array $headers, int &$currentRow, ?string $dataType): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($dataType === 'transactions') {
      // 1. ISI DATA TEKS
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
        $sheetName . '!A' . $currentRow,
        new ValueRange(['values' => [$row1, $row2]]),
        ['valueInputOption' => 'RAW']
      );

      // 2. LOGIKA FORMATTING
      $requests = [];

      // A. MERGE VERTIKAL (Baris 1 & 2) - Kolom A, B, C, D, G
      $colsToMergeVertically = [0,
        1,
        2,
        3,
        6];
      foreach ($colsToMergeVertically as $col) {
        $requests[] = $this->createMergeRequest($sheetId, $currentRow, $currentRow + 1, $col, $col + 1);
      }

      // B. MERGE HORIZONTAL (Hanya Baris 1) - Kolom E ke F (Amount)
      $requests[] = new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $currentRow - 1,
            'endRowIndex' => $currentRow,
            'startColumnIndex' => 4,
            'endColumnIndex' => 6,
          ],
          'mergeType' => 'MERGE_ALL'
        ]
      ]);

      // C. STYLING (Center, Bold, Middle)
      $requests[] = new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $currentRow - 1,
            'endRowIndex' => $currentRow + 1,
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

      $currentRow += 2;
    } else {
      // Logika header standar (tetap sama)
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $currentRow,
        new ValueRange(['values' => [$headers]]),
        ['valueInputOption' => 'RAW']
      );
      $this->applyBoldCenter($spreadsheetId, $sheetId, $currentRow, count($headers));
      $currentRow++;
    }
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

  /**
  * Helper: Format standar (Bold & Center)
  */
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

  /**
  * Tulis data.
  */
  public function writeData(string $spreadsheetId, string $sheetName, array $values, int &$currentRow): int
  {
    if (empty($values)) return 0;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $currentRow,
      new ValueRange(['values' => $values]),
      ['valueInputOption' => 'RAW']
    );
    $endRow = $currentRow + count($values) - 1;
    $currentRow = $endRow + 1;
    return $endRow;
  }

  /**
  * Tulis subtotal (label + detail di bawahnya).
  */
  public function writeSubtotal(string $spreadsheetId, string $sheetName, array $summary, ?string $dataType, int &$currentRow, array $headers): void
  {
    $colCount = count($headers);
    $emptyRow = array_fill(0, $colCount, '');

    if ($dataType === 'transactions') {
      // Baris label SUBTOTAL (kolom A saja, tanpa merge)
      $labelRow = array_fill(0, $colCount, '');
      $labelRow[0] = 'SUBTOTAL';
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $currentRow,
        new ValueRange(['values' => [$labelRow]]),
        ['valueInputOption' => 'RAW']
      );
      $currentRow++;

      // Detail: satu baris per metrik, semuanya di kolom A
      $metrics = [
        'Pemasukan: ' . ($summary['total_income'] ?? 0),
        'Pengeluaran: ' . ($summary['total_expense'] ?? 0),
        'Net: ' . ($summary['net'] ?? 0),
      ];
      foreach ($metrics as $text) {
        $detailRow = array_fill(0, $colCount, '');
        $detailRow[0] = $text;
        $this->client->getSheetsService()->spreadsheets_values->update(
          $spreadsheetId,
          $sheetName . '!A' . $currentRow,
          new ValueRange(['values' => [$detailRow]]),
          ['valueInputOption' => 'RAW']
        );
        $currentRow++;
      }
    } elseif ($dataType === 'transfers') {
      $row1 = $emptyRow; $row1[0] = 'SUBTOTAL';
      $row2 = $emptyRow; $row2[3] = 'Total Transfer: ' . ($summary['total'] ?? 0);
      $rows = [$row1,
        $row2];
    } elseif ($dataType === 'budgets') {
      $row1 = $emptyRow; $row1[0] = 'SUBTOTAL';
      $row2 = $emptyRow;
      $row2[3] = 'Total Limit: ' . ($summary['total_limit'] ?? 0);
      $row2[4] = 'Total Pengeluaran: ' . ($summary['total_spent'] ?? 0);
      $row2[6] = 'Sisa: ' . ($summary['remaining'] ?? 0);
      $rows = [$row1,
        $row2];
    } else {
      return;
    }

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $currentRow,
      new ValueRange(['values' => $rows]),
      ['valueInputOption' => 'RAW']
    );
    $currentRow += count($rows); // update pointer
  }

  /**
  * Tulis footer.
  */
  public function writeFooter(string $spreadsheetId, string $sheetName, int &$currentRow, array $headers): void
  {
    $text = 'Generated by '.config('app.name', 'Laravel').' App - ' . now()->format('d M Y H:i');
    $emptyRow = array_fill(0, count($headers), '');
    $emptyRow[0] = $text;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $currentRow,
      new ValueRange(['values' => [$emptyRow]]),
      ['valueInputOption' => 'RAW']
    );

    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $request = new SheetsRequest(['mergeCells' => [
      'range' => [
        'sheetId' => $sheetId,
        'startRowIndex' => $currentRow-1,
        'endRowIndex' => $currentRow,
        'startColumnIndex' => 0,
        'endColumnIndex' => count($headers),
      ],
      'mergeType' => 'MERGE_ALL',
    ]]);
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);

    $currentRow++;
  }

  /**
  * Clear seluruh sheet.
  */
  public function clearSheet(string $spreadsheetId, string $sheetName): void
  {
    $this->client->getSheetsService()->spreadsheets_values->clear(
      $spreadsheetId,
      $sheetName,
      new ClearValuesRequest()
    );
  }

  /**
  * Tambahkan column chart pemasukan vs pengeluaran.
  *
  * @param int $dataStartRow Baris pertama data (setelah header)
  * @param int $dataEndRow   Baris terakhir data
  * @param int $chartRow     Baris tempat chart akan diletakkan (misal 2 baris setelah footer)
  */
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
                        'startRowIndex' => $dataStartRow - 1, // 0-indexed
                        'endRowIndex' => $dataEndRow,
                        'startColumnIndex' => 0, // Kolom A
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
                        'startColumnIndex' => 4, // Kolom E (Pemasukan)
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
                        'startColumnIndex' => 5, // Kolom F (Pengeluaran)
                        'endColumnIndex' => 6,
                      ]]
                    ]
                  ],
                  'targetAxis' => 'LEFT_AXIS'
                ]
              ],
              'headerCount' => 1 // Baris pertama tiap range adalah header
            ]
          ],
          'position' => [
            'overlayPosition' => [
              'anchorCell' => [
                'sheetId' => $sheetId,
                'rowIndex' => $chartRow - 1,
                'columnIndex' => 6
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
}