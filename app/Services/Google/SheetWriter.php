<?php

namespace Modules\FinTech\Services\Google;

use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Modules\FinTech\Service\Google\GoogleSheetsClient;
use Modules\FinTech\Service\Google\SpreadsheetManager;

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
    $requests = [];
    $colCount = count($headers);

    if ($dataType === 'transactions') {
      // Baris 1: merge kolom A..D, E..F, G sendiri
      $row1 = ['Tanggal',
        'Tipe',
        'Kategori',
        'Dompet',
        'Amount',
        '',
        'Deskripsi'];
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $currentRow . ':G' . $currentRow,
        new ValueRange(['values' => [$row1]]),
        ['valueInputOption' => 'RAW']
      );
      // Merge A..D, E..F
      $requests[] = new SheetsRequest(['mergeCells' => ['range' => [
        'sheetId' => $sheetId, 'startRowIndex' => $currentRow-1, 'endRowIndex' => $currentRow+1,
        'startColumnIndex' => 0, 'endColumnIndex' => 4
      ], 'mergeType' => 'MERGE_COLUMNS']]);
      $requests[] = new SheetsRequest(['mergeCells' => ['range' => [
        'sheetId' => $sheetId, 'startRowIndex' => $currentRow-1, 'endRowIndex' => $currentRow+1,
        'startColumnIndex' => 4, 'endColumnIndex' => 6
      ], 'mergeType' => 'MERGE_COLUMNS']]);

      // Baris 2: sub-header
      $row2 = ['',
        '',
        '',
        '',
        'Pemasukan',
        'Pengeluaran',
        ''];
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . ($currentRow+1) . ':G' . ($currentRow+1),
        new ValueRange(['values' => [$row2]]),
        ['valueInputOption' => 'RAW']
      );
      $currentRow += 2;
    } else {
      $this->client->getSheetsService()->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $currentRow . ':' . chr(64+$colCount) . $currentRow,
        new ValueRange(['values' => [$headers]]),
        ['valueInputOption' => 'RAW']
      );
      $currentRow++;
    }

    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->client->getSheetsService()->spreadsheets->batchUpdate($spreadsheetId, $batch);
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
}