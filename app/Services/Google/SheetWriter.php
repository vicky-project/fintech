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
  public function writeSimpleHeader(string $spreadsheetId, string $sheetName, array $headers, SheetCursor $cursor): void
  {
    $colCount = count($headers);
    $range = $sheetName . '!' . $cursor->getColLetter() . $cursor->row . ':' .
    chr(65 + $cursor->col + $colCount - 1) . $cursor->row;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $range,
      new ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $this->applyHeaderStyle($spreadsheetId, $sheetId, $cursor->row, $colCount, $cursor->col);
    $cursor->advanceRow();
  }

  public function writeSimpleTitle(string $spreadsheetId, string $sheetName, string $title, SheetCursor $cursor): void
  {
    $colCount = 4; // lebar tabel tambahan = 4 kolom
    $range = $sheetName . '!' . $cursor->getColLetter() . $cursor->row . ':' .
    chr(65 + $cursor->col + $colCount - 1) . $cursor->row;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $range,
      new ValueRange(['values' => [[$title]]]),
      ['valueInputOption' => 'RAW']
    );

    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    // Merge & style
    $requests = [
      new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1,
            'endRowIndex' => $cursor->row,
            'startColumnIndex' => $cursor->col,
            'endColumnIndex' => $cursor->col + $colCount,
          ],
          'mergeType' => 'MERGE_ALL',
        ]
      ]),
      new SheetsRequest([
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
      ]),
    ];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);

    $cursor->advanceRow();
  }

  public function writeTitle(string $spreadsheetId, string $sheetName, string $title, SheetCursor $cursor, int $colCount): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $range = $sheetName . '!A' . $cursor->row . ':' . chr(64 + $colCount) . $cursor->row;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $range,
      new ValueRange(['values' => [[$title]]]),
      ['valueInputOption' => 'RAW']
    );

    $requests = [
      new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1,
            'endRowIndex' => $cursor->row,
            'startColumnIndex' => 0,
            'endColumnIndex' => $colCount,
          ],
          'mergeType' => 'MERGE_ALL',
        ]
      ]),
      new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $cursor->row - 1,
            'endRowIndex' => $cursor->row,
            'startColumnIndex' => 0,
            'endColumnIndex' => $colCount,
          ],
          'cell' => [
            'userEnteredFormat' => [
              'textFormat' => ['bold' => true, 'fontSize' => 14],
              'horizontalAlignment' => 'CENTER',
            ],
          ],
          'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
        ],
      ]),
    ];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);

    $cursor->advanceRow();
  }

  // ======================== DATA ========================
  public function writeData(string $spreadsheetId, string $sheetName, array $values, SheetCursor $cursor): int
  {
    if (empty($values)) return 0;

    $startColLetter = $cursor->getColLetter();
    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!' . $startColLetter . $cursor->row,
      new ValueRange(['values' => $values]),
      ['valueInputOption' => 'RAW']
    );

    $endRow = $cursor->row + count($values) - 1;
    $cursor->row = $endRow + 1;
    // col tetap
    return $endRow;
  }

  // ======================== FORMAT MATA UANG ========================
  public function applyCurrencyFormat(
    string $spreadsheetId,
    string $sheetName,
    int $dataStartRow,
    int $dataEndRow,
    array $summary,
    int $startCol = 4,
    int $colCount = 2
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $symbol = $summary['symbol'] ?? 'Rp';
    $thousandsSep = $summary['thousands_separator'] ?? '.';
    $decimalMark = $summary['decimal_mark'] ?? ',';
    $precision = $summary['precision'] ?? 0;

    $pattern = $symbol . ' #' . $thousandsSep . '##0';
    if ($precision > 0) {
      $pattern .= $decimalMark . str_repeat('0', $precision);
    }

    $requests = [
      new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $dataStartRow - 1,
            'endRowIndex' => $dataEndRow,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $startCol + $colCount,
          ],
          'cell' => [
            'userEnteredFormat' => [
              'numberFormat' => [
                'type' => 'CURRENCY',
                'pattern' => $pattern,
              ],
            ],
          ],
          'fields' => 'userEnteredFormat.numberFormat',
        ],
      ]),
    ];

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }

  public function writeSummaryWithStats(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): void {
    // Bangun data ringkasan per bulan
    $grouped = [];
    foreach ($transactions as $row) {
      $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
      if (!$date) continue;
      $key = $date->format('Y-m');
      if (!isset($grouped[$key])) {
        $grouped[$key] = ['income' => 0,
          'expense' => 0,
          'label' => $date->format('M Y')];
      }
      $grouped[$key]['income'] += (float)($row['Pemasukan'] ?? 0);
      $grouped[$key]['expense'] += (float)($row['Pengeluaran'] ?? 0);
    }
    ksort($grouped);

    if (empty($grouped)) return;

    // Hitung total & rata‑rata
    $totalIncome = array_sum(array_column($grouped, 'income'));
    $totalExpense = array_sum(array_column($grouped, 'expense'));
    $monthCount = count($grouped);
    $avgIncome = $monthCount > 0 ? $totalIncome / $monthCount : 0;
    $avgExpense = $monthCount > 0 ? $totalExpense / $monthCount : 0;
    $ratio = $totalIncome > 0 ? ($totalExpense / $totalIncome) * 100 : 0;

    $startCol = $cursor->col; // kolom saat ini (A = 0)

    // Judul tabel
    $this->writeSimpleTitle(
      $spreadsheetId, $sheetName, 'Ringkasan Bulanan & Statistik', $cursor
    );

    // Header tabel
    $headers = ['Bulan',
      'Pemasukan',
      'Pengeluaran',
      'Net'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    // Data per bulan
    $values = [];
    foreach ($grouped as $item) {
      $values[] = [
        $item['label'],
        $item['income'],
        $item['expense'],
        $item['income'] - $item['expense']
      ];
    }
    // Baris Total
    $values[] = [
      'Total',
      $totalIncome,
      $totalExpense,
      $totalIncome - $totalExpense
    ];
    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // Format mata uang untuk kolom B, C, D (indeks 1,2,3 dari startCol)
    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 1, 3
    );

    // Tulis baris statistik tambahan (tanpa header)
    $statsData = [
      ['Rata‑rata Pemasukan/Bulan',
        $avgIncome,
        '',
        ''],
      ['Rata‑rata Pengeluaran/Bulan',
        '',
        $avgExpense,
        ''],
      ['Rasio Pengeluaran (%)',
        '',
        '',
        round($ratio, 1) . '%'],
    ];
    $this->writeData($spreadsheetId, $sheetName, $statsData, $cursor);

    // Format mata uang untuk sel angka di baris statistik
    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - 3, $cursor->row - 3, $summary,
      $startCol + 1, 1
    );
    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - 2, $cursor->row - 2, $summary,
      $startCol + 2, 1
    );
    // Rasio tidak perlu format mata uang, biarkan apa adanya

    // Warna hijau/merah untuk kolom angka
    $this->applySummaryColors(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - count($statsData) - 1, // baris header tabel
      $values, $startCol
    );

    // Border untuk seluruh area (judul s/d statistik)
    $this->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - count($statsData) - 1, // baris judul
      $cursor->row - 1, // baris terakhir statistik
      $startCol, 4, $headers
    );
  }

  // ======================== FOOTER ========================
  public function writeFooter(string $spreadsheetId, string $sheetName, SheetCursor $cursor, array $headers): void
  {
    $text = 'Generated by ' . config('app.name', 'Laravel') . ' App - ' . now()->setTimezone(config('app.timezone'))->format('d M Y H:i');
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
        'cell' => ['userEnteredFormat' => [
          'textFormat' => ['italic' => true, 'foregroundColor' => ['red' => 136/255, 'green' => 136/255, 'blue' => 136/255], 'fontSize' => 10],
          'horizontalAlignment' => 'CENTER',
        ]],
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
    int $chartRow,
    int $chartCol = 0
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName) ?? 0;

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
              'domains' => [[
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
              ]],
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
                'columnIndex' => $chartCol,
              ],
              'widthPixels' => 1200,
              'heightPixels' => 500,
            ]
          ]
        ]
      ]
    ]);

    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => [$chartRequest]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  // Di SheetWriter, tambahkan method baru untuk tren
  public function writeTrendChart(
    string $spreadsheetId,
    string $sheetName,
    int $dataStartRow,
    int $dataEndRow,
    int $chartRow,
    int $startCol = 0
  ): void {
    \Log::info('Chart position', ['startCol' => $startCol, 'chartRow' => $chartRow]);
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName) ?? 0;
    if ($chartRow <= $dataEndRow) {
      $chartRow = $dataEndRow + 2;
    }

    $chartRequest = new SheetsRequest([
      'addChart' => [
        'chart' => [
          'spec' => [
            'title' => 'Tren Net (Pemasukan - Pengeluaran)',
            'basicChart' => [
              'chartType' => 'LINE',
              'legendPosition' => 'BOTTOM_LEGEND',
              'axis' => [
                ['position' => 'BOTTOM_AXIS', 'title' => 'Tanggal'],
                ['position' => 'LEFT_AXIS', 'title' => 'Selisih']
              ],
              'domains' => [[
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
              ]],
              'series' => [[
                'series' => [
                  'sourceRange' => [
                    'sources' => [[
                      'sheetId' => $sheetId,
                      'startRowIndex' => $dataStartRow - 1,
                      'endRowIndex' => $dataEndRow,
                      'startColumnIndex' => 6, // kolom G (Net) atau hasil kalkulasi
                      'endColumnIndex' => 7,
                    ]]
                  ]
                ],
                'targetAxis' => 'LEFT_AXIS'
              ]],
              'headerCount' => 1
            ]
          ],
          'position' => [
            'overlayPosition' => [
              'anchorCell' => [
                'sheetId' => $sheetId,
                'rowIndex' => $chartRow - 1,
                'columnIndex' => $startCol,
              ],
              'widthPixels' => 600,
              'heightPixels' => 300,
            ]
          ]
        ]
      ]
    ]);

    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => [$chartRequest]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  // ======================= TOP SPENDING & Income =======================
  public function writeTopSpendingToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): void {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return;

    usort($expenses, fn($a, $b) => ((float)($b['Pengeluaran'] ?? 0)) <=> ((float)($a['Pengeluaran'] ?? 0)));
    $top5 = array_slice($expenses, 0, 5);

    $startCol = $cursor->col;
    $title = "Top 5 Pengeluaran";
    $this->writeSimpleTitle($spreadsheetId, $sheetName, $title, $cursor);

    $headers = ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $values = [];
    foreach ($top5 as $item) {
      $values[] = [
        $item['Tanggal'] ?? '',
        $item['Kategori'] ?? '',
        (float)($item['Pengeluaran'] ?? 0),
        $item['Deskripsi'] ?? '-'
      ];
    }
    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);

    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 2, 1
    );
    $this->applyTopSpendingColors(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 1, $values, $startCol
    );

    $this->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 2, // baris judul
      $dataEndRow,
      $startCol, count($headers), $headers
    );
  }

  public function writeTopIncomeToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): void {
    $incomes = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pemasukan');
    if (empty($incomes)) return;

    usort($incomes, fn($a, $b) => ((float)($b['Pemasukan'] ?? 0)) <=> ((float)($a['Pemasukan'] ?? 0)));
    $top5 = array_slice($incomes, 0, 5);

    $startCol = $cursor->col;
    $this->writeSimpleTitle($spreadsheetId, $sheetName, 'Top 5 Pemasukan', $cursor);

    $headers = ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $values = [];
    foreach ($top5 as $item) {
      $values[] = [
        $item['Tanggal'] ?? '',
        $item['Kategori'] ?? '',
        (float)($item['Pemasukan'] ?? 0),
        $item['Deskripsi'] ?? '-'
      ];
    }
    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);

    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 2, 1
    );

    // Warna hijau untuk pemasukan
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $requests = [];
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $colJumlah = $startCol + 2;
    $dataStartRow = $cursor->row - count($values);
    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colJumlah, $green, true, $requests);
    }
    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }

    $this->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 2, // baris judul
      $dataEndRow,
      $startCol, count($headers), $headers
    );
  }

  public function writeCategoryExpenseTable(
    string $spreadsheetId,
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary
  ): array {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return [];

    // Hitung total per kategori dan jumlah transaksi per kategori
    $catTotals = [];
    $catCounts = [];
    foreach ($expenses as $item) {
      $cat = $item['Kategori'] ?? 'Lainnya';
      $catTotals[$cat] = ($catTotals[$cat] ?? 0) + (float)($item['Pengeluaran'] ?? 0);
      $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
    }

    // Total keseluruhan
    $totalAll = array_sum($catTotals);
    if ($totalAll <= 0) return [];

    $startCol = $cursor->col;

    // Judul
    $this->writeSimpleTitle($spreadsheetId, $sheetName, 'Kategori Pengeluaran', $cursor);

    // Header (4 kolom)
    $headers = ['Kategori',
      'Total',
      'Persentase',
      'Rata‑rata'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    // Data
    $values = [];
    foreach ($catTotals as $cat => $total) {
      $count = $catCounts[$cat] ?? 1;
      $average = $total / $count;
      $percentage = ($total / $totalAll) * 100;
      $values[] = [
        $cat,
        $total,
        round($percentage, 1) . '%',
        $average
      ];
    }

    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);

    // Format mata uang untuk kolom Total (indeks 1) dan Rata‑rata (indeks 3)
    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 1, 1
    );
    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 3, 1
    );

    // Border
    $this->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 1, $dataEndRow,
      $startCol, count($headers), $headers
    );

    return [
      'headerRow' => $cursor->row - count($values) - 1,
      'dataStartRow' => $cursor->row - count($values),
      'dataEndRow' => $dataEndRow,
      'startCol' => $startCol,
    ];
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

    $colCount = $endCol > 0 ? $endCol : (count($headers) > 0 ? count($headers) : 7);

    $request = new SheetsRequest([
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
      ]
    ]);

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }

  // ======================== FILTER ========================
  public function applyBasicFilter(
    string $spreadsheetId,
    string $sheetName,
    int $headerStartRow,
    int $headerEndRow,
    int $startCol = 0,
    int $colCount = 7
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $request = new SheetsRequest([
      'setBasicFilter' => [
        'filter' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $headerStartRow - 1,
            'endRowIndex' => $headerEndRow,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $colCount,
          ],
        ],
      ],
    ]);

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }

  // ======================== HELPERS ========================
  private function applyHeaderStyle(string $spreadsheetId, int $sheetId, int $row, int $colCount, int $startCol = 0): void
  {
    $requests = [
      new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $row - 1,
            'endRowIndex' => $row,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $startCol + $colCount,
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
            ],
          ],
          'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
        ],
      ]),
    ];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
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
        'cell' => ['userEnteredFormat' => [
          'horizontalAlignment' => 'CENTER',
          'textFormat' => ['bold' => true],
        ]],
        'fields' => 'userEnteredFormat(horizontalAlignment,textFormat)',
      ],
    ]);
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
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

  public function hideColumns(string $spreadsheetId, string $sheetName, int $startIndex, int $count): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $requests = [new SheetsRequest([
      'updateDimensionProperties' => [
        'range' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => $startIndex,
          'endIndex' => $startIndex + $count,
        ],
        'properties' => ['hiddenByUser' => true],
        'fields' => 'hiddenByUser',
      ],
    ])];
    $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
  }

  // ======================== WARNA ========================
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
        'cell' => ['userEnteredFormat' => [
          'textFormat' => [
            'foregroundColor' => $color,
            'bold' => $bold,
          ],
        ]],
        'fields' => 'userEnteredFormat(textFormat)',
      ],
    ]);
  }

  public function applyTransactionColors(
    string $spreadsheetId, string $sheetName, array $values,
    int $dataStartRow, int $dataEndRow
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
    string $spreadsheetId, string $sheetName,
    int $subStartRow, int $subEndRow, array $summary
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
    string $spreadsheetId, string $sheetName,
    int $headerRow, array $values, int $startCol = 0
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
    $colIncome = $startCol + 1;
    $colExpense = $startCol + 2;
    $colNet = $startCol + 3;
    $dataStartRow = $headerRow + 1;

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true, $requests);
      $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true, $requests);
      $netVal = (float)($row[3] ?? 0);
      $netColor = $netVal >= 0 ? $green : $red;
      $this->setCellColor($sheetId, $rowNum, $colNet, $netColor, true, $requests);
    }

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  public function applyTopSpendingColors(
    string $spreadsheetId, string $sheetName,
    int $headerRow, array $values, int $startCol = 0
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || empty($values)) return;

    $requests = [];
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $colJumlah = $startCol + 2;
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