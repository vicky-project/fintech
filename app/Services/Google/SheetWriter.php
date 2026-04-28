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
  public function writeHeaders(string $spreadsheetId, string $sheetName, int $sheetId, array $headers, int &$currentRow, ?string $dataType): void
  {
    if ($dataType === 'transactions') {
      // 1. ISI DATA TEKS (Baris 1 & 2)
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
        new \Google\Service\Sheets\ValueRange(['values' => [$row1, $row2]]),
        ['valueInputOption' => 'RAW']
      );

      // 2. LOGIKA FORMATTING (Merge & Alignment)
      $requests = [
        // Merge Horizontal: "Amount" (Kolom E ke F, Baris 1)
        $this->createMergeRequest($sheetId, $currentRow, $currentRow, 4, 6),

        // Merge Vertikal: Kolom A, B, C, D, dan G (Baris 1 ke 2)
        $this->createMergeRequest($sheetId, $currentRow, $currentRow + 1, 0, 1),
        $this->createMergeRequest($sheetId, $currentRow, $currentRow + 1, 1, 2),
        $this->createMergeRequest($sheetId, $currentRow, $currentRow + 1, 2, 3),
        $this->createMergeRequest($sheetId, $currentRow, $currentRow + 1, 3, 4),
        $this->createMergeRequest($sheetId, $currentRow, $currentRow + 1, 6, 7),

        // Style: Rata Tengah (Center Alignment) & Tebal (Bold) untuk semua Header
        new \Google\Service\Sheets\Request([
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
        ])
      ];

      $batchUpdate = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);

      $currentRow += 2;
    } else {
      // Header standar untuk tipe lain
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $currentRow,
        new \Google\Service\Sheets\ValueRange(['values' => [$headers]]),
        ['valueInputOption' => 'RAW']
      );

      // Opsional: Beri format bold untuk header standar
      $this->applyBoldCenter($spreadsheetId, $sheetId, $currentRow, count($headers));

      $currentRow++;
    }
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
      $row1 = $emptyRow; $row1[0] = 'SUBTOTAL';
      $row2 = $emptyRow;
      $row2[4] = 'Pemasukan: ' . ($summary['total_income'] ?? 0);
      $row2[5] = 'Pengeluaran: ' . ($summary['total_expense'] ?? 0);
      $row2[6] = 'Net: ' . ($summary['net'] ?? 0);
      $rows = [$row1,
        $row2];
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
  * Helper untuk membuat Request Merge
  */
  private function createMergeRequest(int $sheetId, int $startRow, int $endRow, int $startCol, int $endCol): \Google\Service\Sheets\Request
  {
    return new \Google\Service\Sheets\Request([
      'mergeCells' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $startRow - 1, // Konversi ke Index-0
          'endRowIndex' => $endRow, // End index bersifat eksklusif
          'startColumnIndex' => $startCol,
          'endColumnIndex' => $endCol,
        ],
        'mergeType' => 'MERGE_ALL'
      ]
    ]);
  }

  /**
  * Helper untuk format Bold & Center pada header standar
  */
  private function applyBoldCenter(string $spreadsheetId, int $sheetId, int $row, int $colCount): void
  {
    $request = new \Google\Service\Sheets\Request([
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
    $batchUpdate = new \Google\Service\Sheets\BatchUpdateSpreadsheetRequest(['requests' => [$request]]);
    $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }
}