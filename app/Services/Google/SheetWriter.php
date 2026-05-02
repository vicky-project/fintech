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
  protected array $batchRequests = [];
  protected ?int $sheetId = null;

  public function __construct(GoogleSheetsClient $client, SpreadsheetManager $manager) {
    $this->client = $client;
    $this->manager = $manager;
  }

  // ─── BATCH MANAGEMENT ───────────────────────────

  public function beginBatch(string $spreadsheetId, string $sheetName): void
  {
    $this->batchRequests = [];
    $this->sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
  }

  public function commit(string $spreadsheetId): void
  {
    if (!empty($this->batchRequests)) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $this->batchRequests]);
      $this->client->executeWithBackoff(function () use ($spreadsheetId, $batch) {
        return $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
      });
      $this->batchRequests = [];
    }
  }

  private function addRequest(SheetsRequest $request): void
  {
    $this->batchRequests[] = $request;
  }

  // ─── CLEAR SHEET (dalam batch) ─────────────────

  /**
  * Hapus semua isi sheet menggunakan updateCells (jadi bagian batch).
  */
  public function clearSheetBatch(string $spreadsheetId, string $sheetName): void
  {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $this->addRequest(new SheetsRequest([
      'updateCells' => [
        'range' => [
          'sheetId' => $sheetId,
        ],
        'fields' => 'userEnteredValue',
      ],
    ]));
  }

  // ─── METADATA ────────────────────────────────────

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

    foreach (range(0, count($metadata) - 1) as $i) {
      $this->addRequest(new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $this->sheetId,
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

  // ─── TITLE & HEADER ─────────────────────────────

  public function writeTitle(string $spreadsheetId, string $sheetName, string $title, SheetCursor $cursor, int $colCount): void
  {
    $range = $sheetName . '!A' . $cursor->row . ':' . chr(64 + $colCount) . $cursor->row;
    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId, $range,
      new ValueRange(['values' => [[$title]]]),
      ['valueInputOption' => 'RAW']
    );

    $this->addRequest(new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $this->sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => $colCount,
        ],
        'mergeType' => 'MERGE_ALL',
      ],
    ]));
    $this->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $this->sheetId,
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
    ]));
    $cursor->advanceRow();
  }

  public function writeSimpleTitle(string $spreadsheetId, string $sheetName, string $title, SheetCursor $cursor, int $colCount = 4): void
  {
    $range = $sheetName . '!' . $cursor->getColLetter() . $cursor->row . ':' .
    chr(65 + $cursor->col + $colCount - 1) . $cursor->row;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId, $range,
      new ValueRange(['values' => [[$title]]]),
      ['valueInputOption' => 'RAW']
    );

    $this->addRequest(new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $this->sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => $cursor->col,
          'endColumnIndex' => $cursor->col + $colCount,
        ],
        'mergeType' => 'MERGE_ALL',
      ],
    ]));
    $this->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $this->sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => $cursor->col,
          'endColumnIndex' => $cursor->col + $colCount,
        ],
        'cell' => [
          'userEnteredFormat' => [
            'textFormat' => ['bold' => true, 'fontSize' => 11],
            'horizontalAlignment' => 'CENTER',
          ],
        ],
        'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
      ],
    ]));
    $cursor->advanceRow();
  }

  public function writeSimpleHeader(string $spreadsheetId, string $sheetName, array $headers, SheetCursor $cursor): void
  {
    $colCount = count($headers);
    $range = $sheetName . '!' . $cursor->getColLetter() . $cursor->row . ':' .
    chr(65 + $cursor->col + $colCount - 1) . $cursor->row;

    $this->client->getSheetsService()->spreadsheets_values->update(
      $spreadsheetId, $range,
      new ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    $this->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $this->sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => $cursor->col,
          'endColumnIndex' => $cursor->col + $colCount,
        ],
        'cell' => [
          'userEnteredFormat' => [
            'backgroundColor' => ['red' => 79 / 255, 'green' => 129 / 255, 'blue' => 189 / 255],
            'textFormat' => [
              'foregroundColor' => ['red' => 1, 'green' => 1, 'blue' => 1],
              'bold' => true,
              'fontSize' => 11,
            ],
            'horizontalAlignment' => 'CENTER',
            'verticalAlignment' => 'MIDDLE',
          ],
        ],
        'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment,verticalAlignment)',
      ],
    ]));
    $cursor->advanceRow();
  }

  // ─── DATA ────────────────────────────────────────

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
    return $endRow;
  }

  // ─── CURRENCY FORMAT ───────────────────────────

  public function applyCurrencyFormat(
    string $spreadsheetId, string $sheetName,
    int $dataStartRow, int $dataEndRow, array $summary,
    int $startCol = 4, int $colCount = 2
  ): void {
    if ($this->sheetId === null) return;

    $symbol = $summary['symbol'] ?? 'Rp';
    $thousandsSep = $summary['thousands_separator'] ?? '.';
    $decimalMark = $summary['decimal_mark'] ?? ',';
    $precision = $summary['precision'] ?? 0;

    $pattern = $symbol . ' #' . $thousandsSep . '##0';
    if ($precision > 0) {
      $pattern .= $decimalMark . str_repeat('0', $precision);
    }

    $this->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $this->sheetId,
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
    ]));
  }

  // ─── SUMMARY + STATS (dengan batching) ─────────

  public function writeSummaryWithStats(
    string $spreadsheetId, string $sheetName,
    array $transactions, SheetCursor $cursor, array $summary
  ): array {
    $grouped = [];
    foreach ($transactions as $row) {
      $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
      if (!$date) continue;
      $key = $date->format('Y-m');
      if (!isset($grouped[$key])) {
        $grouped[$key] = [
          'income' => 0,
          'expense' => 0,
          'label' => $date->format('M Y')
        ];
      }
      $grouped[$key]['income'] += (float)($row['Pemasukan'] ?? 0);
      $grouped[$key]['expense'] += (float)($row['Pengeluaran'] ?? 0);
    }
    ksort($grouped);
    if (empty($grouped)) return [];

    $totalIncome = array_sum(array_column($grouped, 'income'));
    $totalExpense = array_sum(array_column($grouped, 'expense'));
    $monthCount = count($grouped);
    $avgIncome = $monthCount > 0 ? $totalIncome / $monthCount : 0;
    $avgExpense = $monthCount > 0 ? $totalExpense / $monthCount : 0;
    $ratio = $totalIncome > 0 ? ($totalExpense / $totalIncome) * 100 : 0;

    $startCol = $cursor->col;

    $this->writeSimpleTitle($spreadsheetId, $sheetName, 'Ringkasan Bulanan & Statistik', $cursor);

    $headers = ['Bulan',
      'Pemasukan',
      'Pengeluaran',
      'Net'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $firstDataRow = $cursor->row;
    $firstDataCol = $cursor->col;

    $values = [];
    foreach ($grouped as $item) {
      $values[] = [
        $item['label'],
        $item['income'],
        $item['expense'],
        $item['income'] - $item['expense']
      ];
    }
    $values[] = ['Total',
      $totalIncome,
      $totalExpense,
      $totalIncome - $totalExpense];
    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);
    $lastDataRow = $firstDataRow + count($grouped) - 1;

    // Currency format (kolom B, C, D)
    $this->applyCurrencyFormat($spreadsheetId, $sheetName, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 1, 3);

    // Statistik
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

    // Currency untuk sel statistik
    $this->applyCurrencyFormat($spreadsheetId, $sheetName, $cursor->row - 3, $cursor->row - 3, $summary, $startCol + 1, 1);
    $this->applyCurrencyFormat($spreadsheetId, $sheetName, $cursor->row - 2, $cursor->row - 2, $summary, $startCol + 2, 1);

    // Warna
    $this->applySummaryColors($spreadsheetId, $sheetName, $cursor->row - count($values) - count($statsData) - 1, $values, $startCol);

    // Border
    $this->applyBordersToRange($spreadsheetId, $sheetName, $cursor->row - count($values) - count($statsData) - 1, $cursor->row - 1, $startCol, 4, $headers);

    return [
      'dataStartRow' => $firstDataRow - 1,
      'dataEndRow' => $lastDataRow,
      'dataStartCol' => $firstDataCol,
      'endRow' => $cursor->row - 1,
    ];
  }

  // ─── FOOTER ──────────────────────────────────────

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

    $this->addRequest(new SheetsRequest([
      'mergeCells' => [
        'range' => [
          'sheetId' => $this->sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => count($headers),
        ],
        'mergeType' => 'MERGE_ALL',
      ],
    ]));
    $this->addRequest(new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $this->sheetId,
          'startRowIndex' => $cursor->row - 1,
          'endRowIndex' => $cursor->row,
          'startColumnIndex' => 0,
          'endColumnIndex' => count($headers),
        ],
        'cell' => [
          'userEnteredFormat' => [
            'textFormat' => ['italic' => true, 'foregroundColor' => ['red' => 136 / 255, 'green' => 136 / 255, 'blue' => 136 / 255], 'fontSize' => 10],
            'horizontalAlignment' => 'CENTER',
          ],
        ],
        'fields' => 'userEnteredFormat(textFormat,horizontalAlignment)',
      ],
    ]));
    $cursor->advanceRow();
  }

  // ─── CHART (langsung kirim, tidak bisa batch) ───

  public function writeTransactionChart(
    string $spreadsheetId, string $sheetName,
    int $dataStartRow, int $dataEndRow, int $chartRow, int $chartCol = 0,
    int $domainCol = 0, int $series1Col = 4, int $series2Col = 5
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
                ['position' => 'LEFT_AXIS', 'title' => 'Jumlah'],
              ],
              'domains' => [[
                'domain' => [
                  'sourceRange' => [
                    'sources' => [[
                      'sheetId' => $sheetId,
                      'startRowIndex' => $dataStartRow - 1,
                      'endRowIndex' => $dataEndRow,
                      'startColumnIndex' => $domainCol,
                      'endColumnIndex' => $domainCol + 1,
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
                        'startColumnIndex' => $series1Col,
                        'endColumnIndex' => $series1Col + 1,
                      ]]
                    ]
                  ],
                  'targetAxis' => 'LEFT_AXIS',
                ],
                [
                  'series' => [
                    'sourceRange' => [
                      'sources' => [[
                        'sheetId' => $sheetId,
                        'startRowIndex' => $dataStartRow - 1,
                        'endRowIndex' => $dataEndRow,
                        'startColumnIndex' => $series2Col,
                        'endColumnIndex' => $series2Col + 1,
                      ]]
                    ]
                  ],
                  'targetAxis' => 'LEFT_AXIS',
                ],
              ],
              'headerCount' => 1,
            ],
          ],
          'position' => [
            'overlayPosition' => [
              'anchorCell' => [
                'sheetId' => $sheetId,
                'rowIndex' => $chartRow - 1,
                'columnIndex' => $chartCol,
              ],
              'widthPixels' => 600,
              'heightPixels' => 300,
            ]
          ]
        ]
      ]
    ]);

    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => [$chartRequest]]);
    $this->client->executeWithBackoff(function () use ($spreadsheetId, $batchUpdate) {
      return $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
    });
  }

  public function writeCategoryPieChart(
    string $spreadsheetId, string $sheetName,
    int $dataStartRow, int $dataEndRow,
    int $categoryCol, int $totalCol,
    int $chartRow, int $chartCol = 0
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName) ?? 0;

    $pieRequest = new SheetsRequest([
      'addChart' => [
        'chart' => [
          'spec' => [
            'title' => 'Distribusi Pengeluaran (%)',
            'pieChart' => [
              'legendPosition' => 'LABELED_LEGEND',
              'threeDimensional' => true,
              'domain' => [
                'sourceRange' => [
                  'sources' => [[
                    'sheetId' => $sheetId,
                    'startRowIndex' => $dataStartRow - 1,
                    'endRowIndex' => $dataEndRow,
                    'startColumnIndex' => $categoryCol,
                    'endColumnIndex' => $categoryCol + 1,
                  ]]
                ]
              ],
              'series' => [
                'sourceRange' => [
                  'sources' => [[
                    'sheetId' => $sheetId,
                    'startRowIndex' => $dataStartRow - 1,
                    'endRowIndex' => $dataEndRow,
                    'startColumnIndex' => $totalCol,
                    'endColumnIndex' => $totalCol + 1,
                  ]]
                ]
              ],
            ],
          ],
          'position' => [
            'overlayPosition' => [
              'anchorCell' => [
                'sheetId' => $sheetId,
                'rowIndex' => $chartRow - 1,
                'columnIndex' => $chartCol,
              ],
              'widthPixels' => 450,
              'heightPixels' => 350,
            ]
          ]
        ]
      ]
    ]);

    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => [$pieRequest]]);
    $this->client->executeWithBackoff(function () use ($spreadsheetId, $batchUpdate) {
      return $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
    });
  }

  // ─── TOP SPENDING & INCOME TABLES ─────────────

  public function writeTopSpendingToSheet(
    string $spreadsheetId, string $sheetName,
    array $transactions, SheetCursor $cursor, array $summary
  ): void {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return;

    usort($expenses, fn($a, $b) => ((float)($b['Pengeluaran'] ?? 0)) <=> ((float)($a['Pengeluaran'] ?? 0)));
    $top5 = array_slice($expenses, 0, 5);

    $includeDesc = ($summary['include_description'] ?? true);
    $colCount = $includeDesc ? 4 : 3;

    $startCol = $cursor->col;
    $this->writeSimpleTitle($spreadsheetId, $sheetName, 'Top 5 Pengeluaran', $cursor, $colCount);

    $headers = $includeDesc
    ? ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi']
    : ['Tanggal',
      'Kategori',
      'Jumlah'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $values = [];
    foreach ($top5 as $item) {
      $row = [
        $item['Tanggal'] ?? '',
        $item['Kategori'] ?? '',
        (float)($item['Pengeluaran'] ?? 0),
      ];
      if ($includeDesc) {
        $row[] = $item['Deskripsi'] ?? '-';
      }
      $values[] = $row;
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
      $cursor->row - count($values) - 2, $dataEndRow,
      $startCol, count($headers), $headers
    );
  }

  public function writeTopIncomeToSheet(
    string $spreadsheetId, string $sheetName,
    array $transactions, SheetCursor $cursor, array $summary
  ): void {
    $incomes = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pemasukan');
    if (empty($incomes)) return;

    usort($incomes, fn($a, $b) => ((float)($b['Pemasukan'] ?? 0)) <=> ((float)($a['Pemasukan'] ?? 0)));
    $top5 = array_slice($incomes, 0, 5);

    $includeDesc = ($summary['include_description'] ?? true);
    $colCount = $includeDesc ? 4 : 3;

    $startCol = $cursor->col;
    $this->writeSimpleTitle($spreadsheetId, $sheetName, 'Top 5 Pemasukan', $cursor, $colCount);

    $headers = $includeDesc
    ? ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi']
    : ['Tanggal',
      'Kategori',
      'Jumlah'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $values = [];
    foreach ($top5 as $item) {
      $row = [
        $item['Tanggal'] ?? '',
        $item['Kategori'] ?? '',
        (float)($item['Pemasukan'] ?? 0),
      ];
      if ($includeDesc) {
        $row[] = $item['Deskripsi'] ?? '-';
      }
      $values[] = $row;
    }
    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);

    $this->applyCurrencyFormat(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values), $dataEndRow, $summary,
      $startCol + 2, 1
    );

    // Warna hijau untuk pemasukan
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $green = ['red' => 40 / 255,
      'green' => 167 / 255,
      'blue' => 69 / 255];
    $colJumlah = $startCol + 2;
    $dataStartRow = $cursor->row - count($values);
    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colJumlah, $green, true, $this->batchRequests);
    }

    $this->applyBordersToRange(
      $spreadsheetId, $sheetName,
      $cursor->row - count($values) - 2, $dataEndRow,
      $startCol, count($headers), $headers
    );
  }

  public function writeCategoryExpenseTable(
    string $spreadsheetId, string $sheetName,
    array $transactions, SheetCursor $cursor, array $summary
  ): array {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return [];

    $catTotals = [];
    $catCounts = [];
    foreach ($expenses as $item) {
      $cat = $item['Kategori'] ?? 'Lainnya';
      $catTotals[$cat] = ($catTotals[$cat] ?? 0) + (float)($item['Pengeluaran'] ?? 0);
      $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
    }

    $totalAll = array_sum($catTotals);
    if ($totalAll <= 0) return [];

    $sorted = [];
    foreach ($catTotals as $cat => $total) {
      $count = $catCounts[$cat] ?? 1;
      $average = $total / $count;
      $percentage = ($total / $totalAll) * 100;
      $sorted[] = [
        'cat' => $cat,
        'total' => $total,
        'average' => $average,
        'percentage' => $percentage,
      ];
    }
    usort($sorted, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

    $startCol = $cursor->col;

    // Period dari metadata
    $period = '';
    $metadata = $summary['metadata'] ?? [];
    foreach ($metadata as $line) {
      if (str_starts_with($line, 'Rentang Tanggal:')) {
        $period = trim(substr($line, strlen('Rentang Tanggal:')));
        break;
      }
    }
    if (empty($period)) {
      foreach ($metadata as $line) {
        if (str_starts_with($line, 'Periode Bulan:')) {
          $period = trim(substr($line, strlen('Periode Bulan:')));
          break;
        }
      }
    }
    if (empty($period) && count($expenses) >= 1) {
      $firstDate = \DateTime::createFromFormat('d/m/Y', $expenses[0]['Tanggal'] ?? '');
      $lastDate = \DateTime::createFromFormat('d/m/Y', $expenses[count($expenses) - 1]['Tanggal'] ?? '');
      if ($firstDate && $lastDate) {
        $period = $firstDate->format('d M Y') . ' - ' . $lastDate->format('d M Y');
      }
    }

    $title = 'Persentase Kategori Pengeluaran';
    if ($period) {
      $title .= ' (' . $period . ')';
    }
    $this->writeSimpleTitle($spreadsheetId, $sheetName, $title, $cursor);

    $headers = ['Kategori',
      'Total',
      'Persentase',
      'Rata‑rata'];
    $this->writeSimpleHeader($spreadsheetId, $sheetName, $headers, $cursor);

    $values = [];
    foreach ($sorted as $item) {
      $values[] = [
        $item['cat'],
        $item['total'],
        round($item['percentage'], 1) . '%',
        $item['average'],
      ];
    }
    $dataEndRow = $this->writeData($spreadsheetId, $sheetName, $values, $cursor);

    $this->applyCurrencyFormat($spreadsheetId, $sheetName, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 1, 1);
    $this->applyCurrencyFormat($spreadsheetId, $sheetName, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 3, 1);

    // Warna
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId !== null) {
      $red = ['red' => 220 / 255,
        'green' => 53 / 255,
        'blue' => 69 / 255];
      $orange = ['red' => 255 / 255,
        'green' => 193 / 255,
        'blue' => 7 / 255];
      $green = ['red' => 40 / 255,
        'green' => 167 / 255,
        'blue' => 69 / 255];

      $dataStartRow = $cursor->row - count($values);
      foreach ($sorted as $idx => $item) {
        $rowNum = $dataStartRow + $idx;
        $this->setCellColor($sheetId, $rowNum, $startCol + 1, $red, true, $this->batchRequests);
        $this->setCellColor($sheetId, $rowNum, $startCol + 3, $red, true, $this->batchRequests);

        $pct = $item['percentage'];
        if ($pct >= 30) {
          $color = $red;
        } elseif ($pct >= 10) {
          $color = $orange;
        } else {
          $color = $green;
        }
        $this->setCellColor($sheetId, $rowNum, $startCol + 2, $color, true, $this->batchRequests);
      }
    }

    $this->applyBordersToRange($spreadsheetId, $sheetName, $cursor->row - count($values) - 1, $dataEndRow, $startCol, count($headers), $headers);

    return [
      'headerRow' => $cursor->row - count($values) - 1,
      'dataStartRow' => $cursor->row - count($values),
      'dataEndRow' => $dataEndRow,
      'startCol' => $startCol,
    ];
  }

  // ─── BORDER ─────────────────────────────────────

  public function applyBordersToRange(
    string $spreadsheetId, string $sheetName,
    int $startRow, int $endRow,
    int $startCol = 0, int $endCol = 0, array $headers = []
  ): void {
    if ($this->sheetId === null || $endRow < $startRow) return;
    $colCount = $endCol > 0 ? $endCol : (count($headers) > 0 ? count($headers) : 7);

    $this->addRequest(new SheetsRequest([
      'updateBorders' => [
        'range' => [
          'sheetId' => $this->sheetId,
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
      ],
    ]));
  }

  // ─── FILTER ─────────────────────────────────────

  public function applyBasicFilter(
    string $spreadsheetId, string $sheetName,
    int $headerStartRow, int $headerEndRow,
    int $startCol = 0, int $colCount = 7
  ): void {
    if ($this->sheetId === null) return;
    $this->addRequest(new SheetsRequest([
      'setBasicFilter' => [
        'filter' => [
          'range' => [
            'sheetId' => $this->sheetId,
            'startRowIndex' => $headerStartRow - 1,
            'endRowIndex' => $headerEndRow,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $colCount,
          ],
        ],
      ],
    ]));
  }

  // ─── COLOR HELPERS ─────────────────────────────

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
    string $spreadsheetId, string $sheetName, array $values,
    int $dataStartRow, int $dataEndRow
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $colIncome = 4;
    $colExpense = 5;
    $green = ['red' => 40 / 255,
      'green' => 167 / 255,
      'blue' => 69 / 255];
    $red = ['red' => 220 / 255,
      'green' => 53 / 255,
      'blue' => 69 / 255];
    $black = ['red' => 0,
      'green' => 0,
      'blue' => 0];

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $tipe = $row[1] ?? '';
      if ($tipe === 'Pemasukan') {
        $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true, $this->batchRequests);
        $this->setCellColor($sheetId, $rowNum, $colExpense, $black, false, $this->batchRequests);
      } elseif ($tipe === 'Pengeluaran') {
        $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true, $this->batchRequests);
        $this->setCellColor($sheetId, $rowNum, $colIncome, $black, false, $this->batchRequests);
      }
    }
  }

  public function applySummaryColors(
    string $spreadsheetId, string $sheetName,
    int $headerRow, array $values, int $startCol = 0
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || empty($values)) return;

    $colIncome = $startCol + 1;
    $colExpense = $startCol + 2;
    $colNet = $startCol + 3;
    $dataStartRow = $headerRow + 1;

    $green = ['red' => 40 / 255,
      'green' => 167 / 255,
      'blue' => 69 / 255];
    $red = ['red' => 220 / 255,
      'green' => 53 / 255,
      'blue' => 69 / 255];

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colIncome, $green, true, $this->batchRequests);
      $this->setCellColor($sheetId, $rowNum, $colExpense, $red, true, $this->batchRequests);
      $netVal = (float)($row[3] ?? 0);
      $netColor = $netVal >= 0 ? $green : $red;
      $this->setCellColor($sheetId, $rowNum, $colNet, $netColor, true, $this->batchRequests);
    }
  }

  public function applyTopSpendingColors(
    string $spreadsheetId, string $sheetName,
    int $headerRow, array $values, int $startCol = 0
  ): void {
    $sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null || empty($values)) return;

    $red = ['red' => 220 / 255,
      'green' => 53 / 255,
      'blue' => 69 / 255];
    $colJumlah = $startCol + 2;
    $dataStartRow = $headerRow + 1;

    foreach ($values as $idx => $row) {
      $rowNum = $dataStartRow + $idx;
      $this->setCellColor($sheetId, $rowNum, $colJumlah, $red, true, $this->batchRequests);
    }
  }

  // ─── MISC ───────────────────────────────────────

  /**
  * Clear sheet menggunakan API clear (bukan batch) – hanya digunakan jika tidak dalam batch.
  */
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

    $this->addRequest(new SheetsRequest([
      'autoResizeDimensions' => [
        'dimensions' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => 0,
          'endIndex' => $columnCount,
        ],
      ],
    ]));
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
}