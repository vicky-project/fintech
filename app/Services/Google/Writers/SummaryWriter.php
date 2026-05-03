<?php

namespace Modules\FinTech\Services\Google\Writers;

use Modules\FinTech\Services\Google\SheetCursor;

class SummaryWriter
{
  public function __construct(
    protected TitleWriter $titleWriter,
    protected HeaderWriter $headerWriter,
    protected DataWriter $dataWriter,
    protected CurrencyFormatter $currencyFormatter,
    protected ColorApplier $colorApplier,
    protected BorderApplier $borderApplier,
  ) {}

  public function writeSummaryWithStats(
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary,
    int $sheetId
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

    $this->titleWriter->writeSimpleTitle($sheetName, 'Ringkasan Bulanan & Statistik', $cursor, 4, $sheetId);
    $headers = ['Bulan',
      'Pemasukan',
      'Pengeluaran',
      'Net'];
    $this->headerWriter->writeSimpleHeader($sheetName, $headers, $cursor, $sheetId);

    $firstDataRow = $cursor->row;
    $firstDataCol = $cursor->col;

    $values = [];
    foreach ($grouped as $item) {
      $values[] = [$item['label'],
        $item['income'],
        $item['expense'],
        $item['income'] - $item['expense']];
    }
    $values[] = ['Total',
      $totalIncome,
      $totalExpense,
      $totalIncome - $totalExpense];
    $dataEndRow = $this->dataWriter->writeData($sheetName, $values, $cursor);
    $lastDataRow = $firstDataRow + count($grouped) - 1;

    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 1, 3);

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
    $this->dataWriter->writeData($sheetName, $statsData, $cursor);

    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - 3, $cursor->row - 3, $summary, $startCol + 1, 1);
    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - 2, $cursor->row - 2, $summary, $startCol + 2, 1);

    $this->colorApplier->applySummaryColors($sheetId, $cursor->row - count($values) - count($statsData) - 1, $values, $startCol);
    $this->borderApplier->applyBordersToRange($sheetId, $cursor->row - count($values) - count($statsData) - 1, $cursor->row - 1, $startCol, 4, 4);

    return [
      'dataStartRow' => $firstDataRow - 1,
      'dataEndRow' => $lastDataRow,
      'dataStartCol' => $firstDataCol,
      'endRow' => $cursor->row - 1,
    ];
  }

  public function writeTopSpendingToSheet(
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary,
    int $sheetId
  ): void {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    if (empty($expenses)) return;

    usort($expenses, fn($a, $b) => ((float)($b['Pengeluaran'] ?? 0)) <=> ((float)($a['Pengeluaran'] ?? 0)));
    $top5 = array_slice($expenses, 0, 5);

    $includeDesc = ($summary['include_description'] ?? true);
    $colCount = $includeDesc ? 4 : 3;
    $startCol = $cursor->col;

    $this->titleWriter->writeSimpleTitle($sheetName, 'Top 5 Pengeluaran', $cursor, $colCount, $sheetId);

    $headers = $includeDesc
    ? ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi']
    : ['Tanggal',
      'Kategori',
      'Jumlah'];
    $this->headerWriter->writeSimpleHeader($sheetName, $headers, $cursor, $sheetId);

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
    $dataEndRow = $this->dataWriter->writeData($sheetName, $values, $cursor);

    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 2, 1);
    $this->colorApplier->applyTopSpendingColors($sheetId, $cursor->row - count($values) - 1, $values, $startCol);
    $this->borderApplier->applyBordersToRange($sheetId, $cursor->row - count($values) - 2, $dataEndRow, $startCol, count($headers), count($headers));
  }

  public function writeTopIncomeToSheet(
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary,
    int $sheetId
  ): void {
    $incomes = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pemasukan');
    if (empty($incomes)) return;

    usort($incomes, fn($a, $b) => ((float)($b['Pemasukan'] ?? 0)) <=> ((float)($a['Pemasukan'] ?? 0)));
    $top5 = array_slice($incomes, 0, 5);

    $includeDesc = ($summary['include_description'] ?? true);
    $colCount = $includeDesc ? 4 : 3;
    $startCol = $cursor->col;

    $this->titleWriter->writeSimpleTitle($sheetName, 'Top 5 Pemasukan', $cursor, $colCount, $sheetId);

    $headers = $includeDesc
    ? ['Tanggal',
      'Kategori',
      'Jumlah',
      'Deskripsi']
    : ['Tanggal',
      'Kategori',
      'Jumlah'];
    $this->headerWriter->writeSimpleHeader($sheetName, $headers, $cursor, $sheetId);

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
    $dataEndRow = $this->dataWriter->writeData($sheetName, $values, $cursor);

    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 2, 1);

    // Pewarnaan hijau khusus pemasukan
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];
    $colJumlah = $startCol + 2;
    $dataStartRow = $cursor->row - count($values);
    foreach ($values as $idx => $row) {
      $this->colorApplier->setCellColorPublic($sheetId, $dataStartRow + $idx, $colJumlah, $green, true);
    }

    $this->borderApplier->applyBordersToRange($sheetId, $cursor->row - count($values) - 2, $dataEndRow, $startCol, count($headers), count($headers));
  }

  public function writeCategoryExpenseTable(
    string $sheetName,
    array $transactions,
    SheetCursor $cursor,
    array $summary,
    int $sheetId
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
      $sorted[] = compact('cat', 'total', 'average', 'percentage');
    }
    usort($sorted, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

    $startCol = $cursor->col;

    // Ambil periode dari metadata
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
      $lastDate = \DateTime::createFromFormat('d/m/Y', $expenses[count($expenses)-1]['Tanggal'] ?? '');
      if ($firstDate && $lastDate) {
        $period = $firstDate->format('d M Y') . ' - ' . $lastDate->format('d M Y');
      }
    }

    $title = 'Persentase Kategori Pengeluaran';
    if ($period) $title .= ' (' . $period . ')';
    $this->titleWriter->writeSimpleTitle($sheetName, $title, $cursor, 4, $sheetId);

    $headers = ['Kategori',
      'Total',
      'Persentase',
      'Rata‑rata'];
    $this->headerWriter->writeSimpleHeader($sheetName, $headers, $cursor, $sheetId);

    $values = [];
    foreach ($sorted as $item) {
      $values[] = [
        $item['cat'],
        $item['total'],
        round($item['percentage'], 1) . '%',
        $item['average'],
      ];
    }
    $dataEndRow = $this->dataWriter->writeData($sheetName, $values, $cursor);

    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 1, 1);
    $this->currencyFormatter->applyCurrencyFormat($sheetId, $cursor->row - count($values), $dataEndRow, $summary, $startCol + 3, 1);

    // Pewarnaan
    $red = ['red' => 220/255,
      'green' => 53/255,
      'blue' => 69/255];
    $orange = ['red' => 255/255,
      'green' => 193/255,
      'blue' => 7/255];
    $green = ['red' => 40/255,
      'green' => 167/255,
      'blue' => 69/255];

    $dataStartRow = $cursor->row - count($values);
    foreach ($sorted as $idx => $item) {
      $rowNum = $dataStartRow + $idx;
      $this->colorApplier->setCellColorPublic($sheetId, $rowNum, $startCol + 1, $red, true);
      $this->colorApplier->setCellColorPublic($sheetId, $rowNum, $startCol + 3, $red, true);

      $pct = $item['percentage'];
      if ($pct >= 30) {
        $color = $red;
      } elseif ($pct >= 10) {
        $color = $orange;
      } else {
        $color = $green;
      }
      $this->colorApplier->setCellColorPublic($sheetId, $rowNum, $startCol + 2, $color, true);
    }

    $this->borderApplier->applyBordersToRange($sheetId, $cursor->row - count($values) - 1, $dataEndRow, $startCol, count($headers), count($headers));

    return [
      'headerRow' => $cursor->row - count($values) - 1,
      'dataStartRow' => $cursor->row - count($values),
      'dataEndRow' => $dataEndRow,
      'startCol' => $startCol,
    ];
  }
}