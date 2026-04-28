<?php

namespace Modules\FinTech\Services;

use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Exception as GoogleException;
use Google\Service\Sheets\Spreadsheet;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\ValueRange;
use Google\Service\Sheets\ClearValuesRequest;
use Modules\FinTech\Models\UserSetting;
use Illuminate\Support\Facades\Log;

class GoogleSheetsService
{
  const SHEET_TRANSACTIONS = 'Transaksi';
  const SHEET_TRANSFERS = 'Transfer';
  const SHEET_BUDGETS = 'Budget';

  protected GoogleClient $client;
  protected GoogleSheets $service;
  protected GoogleDrive $driveService;

  /**
  * Setup service untuk user tertentu (dengan token OAuth user).
  */
  public function setupForUser($user): void
  {
    $this->client = $this->createUserClient($user);
    $this->service = new GoogleSheets($this->client);
    $this->driveService = new GoogleDrive($this->client);
  }

  /**
  * Buat GoogleClient dengan token user.
  */
  protected function createUserClient($user): GoogleClient
  {
    $client = new GoogleClient();
    $client->setClientId(config('fintech.google.oauth_client_id'));
    $client->setClientSecret(config('fintech.google.oauth_client_secret'));
    $client->setRedirectUri(config('fintech.google.oauth_redirect_uri'));
    $client->addScope([GoogleSheets::SPREADSHEETS, GoogleDrive::DRIVE_FILE]);
    $client->setAccessType('offline');

    $setting = UserSetting::where('user_id', $user->id)->first();
    if ($setting && $setting->google_access_token) {
      $token = [
        'access_token' => $setting->google_access_token,
        'refresh_token' => $setting->google_refresh_token,
        'expires_in' => 3599,
      ];
      $client->setAccessToken($token);

      // Refresh token jika expired
      if ($client->isAccessTokenExpired()) {
        if ($client->getRefreshToken()) {
          $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
          if (!isset($newToken['error'])) {
            $this->saveToken($setting, $newToken);
            $client->setAccessToken($newToken);
          }
        }
      }
    }

    return $client;
  }

  /**
  * Simpan token baru ke user_settings.
  */
  protected function saveToken(UserSetting $setting, array $token): void
  {
    $setting->google_access_token = $token['access_token'];
    if (isset($token['refresh_token'])) {
      $setting->google_refresh_token = $token['refresh_token'];
    }
    if (isset($token['expires_in'])) {
      $setting->google_token_expires_at = now()->addSeconds($token['expires_in']);
    }
    $setting->save();
  }

  /**
  * Dapatkan atau buat spreadsheet untuk user.
  */
  public function getOrCreateSpreadsheet($user): string
  {
    $setting = UserSetting::where('user_id', $user->id)->first();
    $spreadsheetId = $setting->google_spreadsheet_id ?? null;

    if ($spreadsheetId) {
      try {
        $this->service->spreadsheets->get($spreadsheetId);
        return $spreadsheetId;
      } catch (\Exception $e) {
        Log::warning("Spreadsheet user {$user->id} tidak valid, dibuat baru.");
        $spreadsheetId = null;
      }
    }

    $spreadsheetId = $this->createSpreadsheetForUser($user);
    $setting->google_spreadsheet_id = $spreadsheetId;
    $setting->save();

    return $spreadsheetId;
  }

  /**
  * Membuat spreadsheet baru dengan 3 sheet.
  */
  public function createSpreadsheetForUser($user): string
  {
    try {
      $title = "FinTech - " . ($user->name ?? "User {$user->id}");

      $spreadsheet = new Spreadsheet([
        'properties' => ['title' => $title],
      ]);

      $spreadsheet = $this->service->spreadsheets->create($spreadsheet);
      $spreadsheetId = $spreadsheet->spreadsheetId;

      // Rename sheet pertama
      $sheets = $spreadsheet->getSheets();
      if (count($sheets) > 0) {
        $sheetId = $sheets[0]->getProperties()->getSheetId();
        $this->renameSheet($spreadsheetId, $sheetId, self::SHEET_TRANSACTIONS);
      }

      // Buat sheet Transfer dan Budget
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_TRANSFERS);
      $this->addSheetIfNotExists($spreadsheetId, self::SHEET_BUDGETS);

      Log::info("Spreadsheet dibuat untuk user {$user->id}", [
        'spreadsheet_id' => $spreadsheetId,
      ]);

      return $spreadsheetId;
    } catch(GoogleException $e) {
      Log::error("Google Error:", [
        'message' => $e->getMessage(),
        'trace' => $e->getTrace()
      ]);
      throw $e;
    } catch (\Exception $e) {
      throw $e;
    }
  }

  /**
  * Menulis data ke sheet tertentu.
  */
  public function exportDataToSheet(
    string $spreadsheetId,
    string $sheetName,
    array $data,
    bool $clear = true,
    ?array $metadata = null,
    ?array $summary = null,
    ?string $dataType = null
  ): void
  {
    if (empty($data)) return;

    $this->addSheetIfNotExists($spreadsheetId, $sheetName);

    if ($clear) {
      $this->service->spreadsheets_values->clear(
        $spreadsheetId,
        $sheetName,
        new ClearValuesRequest()
      );
    }

    // Hitung offset baris untuk data
    $startRow = 1; // baris pertama setelah clear
    $metaRows = 0;
    if ($metadata) {
      $this->writeMetadata($spreadsheetId, $sheetName, $metadata, $startRow);
      $metaRows = count($metadata);
      $startRow += $metaRows + 1; // +1 baris kosong setelah metadata
    }

    // Tulis header tabel
    $headers = array_keys($data[0]);
    $values = array_map(fn($row) => array_values($row), $data);

    $this->service->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $startRow,
      new ValueRange(['values' => [$headers]]),
      ['valueInputOption' => 'RAW']
    );

    $headerEndCol = chr(64 + count($headers)); // A, B, C, ...
    $headerRange = $sheetName . '!A' . $startRow . ':' . $headerEndCol . $startRow;

    // Tulis data
    $dataStartRow = $startRow + 1;
    if (!empty($values)) {
      $this->service->spreadsheets_values->update(
        $spreadsheetId,
        $sheetName . '!A' . $dataStartRow,
        new ValueRange(['values' => $values]),
        ['valueInputOption' => 'RAW']
      );
    }

    $dataEndRow = $dataStartRow + count($values) - 1;

    // Tulis footer/subtotal jika ada
    $footerRows = 0;
    if ($summary) {
      $footerRows = $this->writeSubtotal($spreadsheetId, $sheetName, $summary, $dataType, $dataEndRow + 2, $headers);
    }

    // Terapkan styling
    $this->applyStyling(
      $spreadsheetId, $sheetName,
      $startRow, $headerEndCol,
      $dataStartRow, $dataEndRow,
      $dataType, $values, $headers, $summary
    );

    // Auto-resize kolom
    $this->autoResizeColumns($spreadsheetId, $sheetName, count($headers));
  }

  /**
  * URL spreadsheet.
  */
  public function getSpreadsheetUrl(string $spreadsheetId): string
  {
    return "https://docs.google.com/spreadsheets/d/{$spreadsheetId}";
  }

  // ----- Helper methods -----

  protected function addSheetIfNotExists(string $spreadsheetId, string $sheetName): void
  {
    $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
    $existingNames = array_map(
      fn($s) => $s->getProperties()->getTitle(),
      $spreadsheet->getSheets()
    );

    if (!in_array($sheetName, $existingNames)) {
      $requests = [new SheetsRequest([
        'addSheet' => ['properties' => ['title' => $sheetName]],
      ])];
      $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
    }
  }

  protected function renameSheet(string $spreadsheetId, int $sheetId, string $newName): void
  {
    $requests = [new SheetsRequest([
      'updateSheetProperties' => [
        'properties' => ['sheetId' => $sheetId, 'title' => $newName],
        'fields' => 'title',
      ],
    ])];
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  protected function autoResizeColumns(string $spreadsheetId, string $sheetName, int $columnCount): void
  {
    $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
    if ($sheetId === null) return;

    $requests = [new SheetsRequest([
      'autoResizeDimensions' => [
        'dimensions' => [
          'sheetId' => $sheetId,
          'dimension' => 'COLUMNS',
          'startIndex' => 0,
          'endIndex' => $columnCount,
        ],
      ],
    ])];
    $batchUpdate = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
    $this->service->spreadsheets->batchUpdate($spreadsheetId, $batchUpdate);
  }

  protected function getSheetIdByName(string $spreadsheetId, string $sheetName): ?int
  {
    $spreadsheet = $this->service->spreadsheets->get($spreadsheetId);
    foreach ($spreadsheet->getSheets() as $sheet) {
      if ($sheet->getProperties()->getTitle() === $sheetName) {
        return $sheet->getProperties()->getSheetId();
      }
    }
    return null;
  }

  protected function writeMetadata(string $spreadsheetId, string $sheetName, array $metadata, int $startRow): void
  {
    $rows = [];
    foreach ($metadata as $line) {
      $rows[] = [$line];
    }
    $this->service->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $startRow,
      new ValueRange(['values' => $rows]),
      ['valueInputOption' => 'RAW']
    );

    // Merge cells untuk setiap baris metadata
    $requests = [];
    foreach (range(0, count($metadata) - 1) as $i) {
      $requests[] = new SheetsRequest([
        'mergeCells' => [
          'range' => [
            'sheetId' => $this->getSheetIdByName($spreadsheetId, $sheetName),
            'startRowIndex' => $startRow - 1 + $i,
            'endRowIndex' => $startRow + $i,
            'startColumnIndex' => 0,
            'endColumnIndex' => 6, // merge A..G
          ],
          'mergeType' => 'MERGE_ALL',
        ]
      ]);
    }
    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->service->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }

  protected function writeSubtotal(string $spreadsheetId, string $sheetName, array $summary, ?string $dataType, int $startRow, array $headers): int
  {
    $rows = [];
    $colCount = count($headers);

    if ($dataType === 'transactions') {
      // Asumsikan kolom: Tanggal(A), Tipe(B), Kategori(C), Dompet(D), Pemasukan(E), Pengeluaran(F), Deskripsi(G)
      $rows[] = array_merge(['SUBTOTAL', '', '', ''], [
        'Pemasukan: ' . ($summary['total_income'] ?? 0),
        'Pengeluaran: ' . ($summary['total_expense'] ?? 0),
        'Net: ' . ($summary['net'] ?? 0),
      ]);
      $writtenRows = 1;
    } elseif ($dataType === 'transfers') {
      // Kolom: Tanggal(A), Dari(B), Ke(C), Jumlah(D), Deskripsi(E)
      $rows[] = array_merge(['SUBTOTAL', '', ''], [
        'Total Transfer: ' . ($summary['total'] ?? 0),
        ''
      ]);
      $writtenRows = 1;
    } elseif ($dataType === 'budgets') {
      // Kolom: Kategori(A), Dompet(B), Periode(C), Limit(D), Pengeluaran(E), Persentase(F), Status(G)
      $rows[] = array_merge(['SUBTOTAL', '', ''], [
        'Total Limit: ' . ($summary['total_limit'] ?? 0),
        'Total Pengeluaran: ' . ($summary['total_spent'] ?? 0),
        '',
        'Sisa: ' . ($summary['remaining'] ?? 0),
      ]);
      $writtenRows = 1;
    } else {
      return 0;
    }

    $this->service->spreadsheets_values->update(
      $spreadsheetId,
      $sheetName . '!A' . $startRow,
      new ValueRange(['values' => $rows]),
      ['valueInputOption' => 'RAW']
    );

    return $writtenRows;
  }

  protected function applyStyling(
    string $spreadsheetId,
    string $sheetName,
    int $headerRow,
    string $headerEndCol,
    int $dataStartRow,
    int $dataEndRow,
    ?string $dataType,
    array $values,
    array $headers,
    ?array $summary = null
  ): void {
    $sheetId = $this->getSheetIdByName($spreadsheetId, $sheetName);
    $requests = [];

    // --- 1. Style header (background biru, bold putih) ---
    $requests[] = new SheetsRequest([
      'repeatCell' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $headerRow - 1,
          'endRowIndex' => $headerRow,
          'startColumnIndex' => 0,
          'endColumnIndex' => count($headers),
        ],
        'cell' => [
          'userEnteredFormat' => [
            'backgroundColor' => ['red' => 79/255, 'green' => 129/255, 'blue' => 189/255],
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
    ]);

    // --- 2. Border seluruh data (header + data) ---
    $requests[] = new SheetsRequest([
      'updateBorders' => [
        'range' => [
          'sheetId' => $sheetId,
          'startRowIndex' => $headerRow - 1,
          'endRowIndex' => $dataEndRow,
          'startColumnIndex' => 0,
          'endColumnIndex' => count($headers),
        ],
        'top' => ['style' => 'SOLID', 'width' => 1, 'color' => ['red' => 0, 'green' => 0, 'blue' => 0]],
        'bottom' => ['style' => 'SOLID', 'width' => 1, 'color' => ['red' => 0, 'green' => 0, 'blue' => 0]],
        'left' => ['style' => 'SOLID', 'width' => 1, 'color' => ['red' => 0, 'green' => 0, 'blue' => 0]],
        'right' => ['style' => 'SOLID', 'width' => 1, 'color' => ['red' => 0, 'green' => 0, 'blue' => 0]],
        'innerHorizontal' => ['style' => 'SOLID', 'width' => 1, 'color' => ['red' => 0, 'green' => 0, 'blue' => 0]],
        'innerVertical' => ['style' => 'SOLID', 'width' => 1, 'color' => ['red' => 0, 'green' => 0, 'blue' => 0]],
      ],
    ]);

    // --- 3. Warna pemasukan/pengeluaran untuk transaksi ---
    if ($dataType === 'transactions') {
      // Kolom: A=Tanggal, B=Tipe, C=Kategori, D=Dompet, E=Pemasukan, F=Pengeluaran, G=Deskripsi
      $colEIndex = 4; // kolom E (0-based)
      $colFIndex = 5; // kolom F

      foreach ($values as $idx => $row) {
        $rowNum = $dataStartRow + $idx; // baris excel (1-based)
        $tipe = $row[1] ?? ''; // kolom B (Tipe)
        if ($tipe === 'Pemasukan') {
          $requests[] = new SheetsRequest([
            'repeatCell' => [
              'range' => [
                'sheetId' => $sheetId,
                'startRowIndex' => $rowNum - 1,
                'endRowIndex' => $rowNum,
                'startColumnIndex' => $colEIndex,
                'endColumnIndex' => $colEIndex + 1,
              ],
              'cell' => [
                'userEnteredFormat' => [
                  'textFormat' => [
                    'foregroundColor' => ['red' => 40/255, 'green' => 167/255, 'blue' => 69/255],
                    'bold' => true,
                  ],
                ],
              ],
              'fields' => 'userEnteredFormat(textFormat)',
            ],
          ]);
          // Reset kolom F ke default (tidak merah)
          $requests[] = new SheetsRequest([
            'repeatCell' => [
              'range' => [
                'sheetId' => $sheetId,
                'startRowIndex' => $rowNum - 1,
                'endRowIndex' => $rowNum,
                'startColumnIndex' => $colFIndex,
                'endColumnIndex' => $colFIndex + 1,
              ],
              'cell' => [
                'userEnteredFormat' => [
                  'textFormat' => [
                    'foregroundColor' => ['red' => 0, 'green' => 0, 'blue' => 0],
                    'bold' => false,
                  ],
                ],
              ],
              'fields' => 'userEnteredFormat(textFormat)',
            ],
          ]);
        } elseif ($tipe === 'Pengeluaran') {
          $requests[] = new SheetsRequest([
            'repeatCell' => [
              'range' => [
                'sheetId' => $sheetId,
                'startRowIndex' => $rowNum - 1,
                'endRowIndex' => $rowNum,
                'startColumnIndex' => $colFIndex,
                'endColumnIndex' => $colFIndex + 1,
              ],
              'cell' => [
                'userEnteredFormat' => [
                  'textFormat' => [
                    'foregroundColor' => ['red' => 220/255, 'green' => 53/255, 'blue' => 69/255],
                    'bold' => true,
                  ],
                ],
              ],
              'fields' => 'userEnteredFormat(textFormat)',
            ],
          ]);
          $requests[] = new SheetsRequest([
            'repeatCell' => [
              'range' => [
                'sheetId' => $sheetId,
                'startRowIndex' => $rowNum - 1,
                'endRowIndex' => $rowNum,
                'startColumnIndex' => $colEIndex,
                'endColumnIndex' => $colEIndex + 1,
              ],
              'cell' => [
                'userEnteredFormat' => [
                  'textFormat' => [
                    'foregroundColor' => ['red' => 0, 'green' => 0, 'blue' => 0],
                    'bold' => false,
                  ],
                ],
              ],
              'fields' => 'userEnteredFormat(textFormat)',
            ],
          ]);
        }
      }

      // Rata kanan untuk kolom angka (E dan F)
      $requests[] = new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $dataStartRow - 1,
            'endRowIndex' => $dataEndRow,
            'startColumnIndex' => $colEIndex,
            'endColumnIndex' => $colFIndex + 1,
          ],
          'cell' => [
            'userEnteredFormat' => [
              'horizontalAlignment' => 'RIGHT',
            ],
          ],
          'fields' => 'userEnteredFormat(horizontalAlignment)',
        ],
      ]);
    }

    // --- 4. Style footer/subtotal ---
    $footerRow = $dataEndRow + 2; // setelah data + 1 baris kosong
    if ($summary) {
      $requests[] = new SheetsRequest([
        'repeatCell' => [
          'range' => [
            'sheetId' => $sheetId,
            'startRowIndex' => $footerRow - 1,
            'endRowIndex' => $footerRow,
            'startColumnIndex' => 0,
            'endColumnIndex' => count($headers),
          ],
          'cell' => [
            'userEnteredFormat' => [
              'backgroundColor' => ['red' => 217/255, 'green' => 226/255, 'blue' => 243/255],
              'textFormat' => ['bold' => true, 'fontSize' => 11],
              'borders' => [
                'top' => ['style' => 'SOLID', 'width' => 1],
                'bottom' => ['style' => 'SOLID', 'width' => 1],
                'left' => ['style' => 'SOLID', 'width' => 1],
                'right' => ['style' => 'SOLID', 'width' => 1],
              ],
            ],
          ],
          'fields' => 'userEnteredFormat(backgroundColor,textFormat,borders)',
        ],
      ]);
    }

    // Jalankan semua request
    if ($requests) {
      $batch = new BatchUpdateSpreadsheetRequest(['requests' => $requests]);
      $this->service->spreadsheets->batchUpdate($spreadsheetId, $batch);
    }
  }
}