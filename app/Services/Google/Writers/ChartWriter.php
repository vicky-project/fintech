<?php

namespace Modules\FinTech\Services\Google\Writers;

use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Services\Google\GoogleSheetsClient;

class ChartWriter
{
  public function __construct(
    protected StyleBuilder $styleBuilder,
    protected GoogleSheetsClient $client
  ) {}

  public function addTransactionChartRequest(
    int $sheetId,
    int $dataStartRow,
    int $dataEndRow,
    int $chartRow,
    int $chartCol = 0,
    int $domainCol = 0,
    int $series1Col = 4,
    int $series2Col = 5
  ): void {
    $this->styleBuilder->addRequest(new SheetsRequest([
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
    ]));
  }

  public function addCategoryPieChartRequest(
    int $sheetId,
    int $dataStartRow,
    int $dataEndRow,
    int $categoryCol,
    int $totalCol,
    int $chartRow,
    int $chartCol = 0
  ): void {
    $this->styleBuilder->addRequest(new SheetsRequest([
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
    ]));
  }

  /**
  * Kirim chart langsung sebagai request terpisah (jika diperlukan).
  */
  public function writeTransactionChart(
    string $spreadsheetId,
    int $sheetId,
    int $dataStartRow,
    int $dataEndRow,
    int $chartRow,
    int $chartCol = 0,
    int $domainCol = 0,
    int $series1Col = 4,
    int $series2Col = 5
  ): void {
    $request = new SheetsRequest([
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

    $batch = new BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->executeWithBackoff(function () use ($spreadsheetId, $batch) {
      return $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
    });
  }
}