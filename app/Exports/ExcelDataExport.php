<?php

namespace Modules\FinTech\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Modules\FinTech\Exports\ChartDataProcessor;

class ExcelDataExport implements WithHeadings, WithStyles, ShouldAutoSize, WithEvents, WithTitle
{
  protected string $type;
  protected array $data;
  protected array $summary;
  protected ?string $customTitle = null;

  private array $headerStyle;
  private array $footerStyle;
  private array $infoStyle;
  private array $metadataStyle;
  private array $emptyStyle;

  public function __construct(string $type, array $data, array $summary, ?string $customTitle = null) {
    $this->type = $type;
    $this->data = $data;
    $this->summary = $summary;
    $this->customTitle = $customTitle;

    $this->headerStyle = [
      'font' => ['bold' => true,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 12],
      'fill' => ['fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '4F81BD']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ];

    $this->footerStyle = [
      'font' => ['italic' => true,
        'color' => ['rgb' => '888888'],
        'size' => 10],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ];

    $this->infoStyle = [
      'font' => ['bold' => true,
        'size' => 12,
        'color' => ['rgb' => '1F4E79']],
    ];

    $this->metadataStyle = [
      'font' => ['size' => 10,
        'italic' => true],
    ];

    $this->emptyStyle = [
      'font' => ['italic' => true,
        'color' => ['rgb' => '888888']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ];
  }

  // -------- Interface methods ----------

  public function headings(): array
  {
    $base = !empty($this->data) && is_array($this->data[0])
    ? array_keys($this->data[0])
    : match ($this->type) {
      'transactions' => ['Tanggal',
        'Tipe',
        'Kategori',
        'Dompet',
        'Pemasukan',
        'Pengeluaran',
        'Deskripsi'],
      'transfers' => ['Tanggal',
        'Dari',
        'Ke',
        'Jumlah',
        'Deskripsi'],
      'budgets' => ['Kategori',
        'Dompet',
        'Periode',
        'Limit',
        'Pengeluaran',
        'Persentase',
        'Status'],
      default => [],
      };

      // Jika include_description false, hapus 'Deskripsi' dari array
      if (($this->type === 'transactions' || $this->type === 'transfers') && !($this->summary['include_description'] ?? true)) {
        $base = array_values(array_diff($base, ['Deskripsi']));
      }

      return $base;
    }

    public function title(): string
    {
      if ($this->customTitle) {
        return $this->customTitle;
      }

      return match ($this->type) {
        'transactions' => 'Riwayat Transaksi',
        'transfers' => 'Riwayat Transfer',
        'budgets' => 'Ringkasan Budget',
      default => 'Laporan Lengkap Keuangan'
      };
    }

    public function styles(Worksheet $sheet) {}

    // -------- Events ----------

    public function registerEvents(): array
    {
      return [
        AfterSheet::class => function (AfterSheet $event) {
          $sheet = $event->sheet->getDelegate();
          $metadata = $this->summary['metadata'] ?? [];
          $metaCount = count($metadata);
          $highestCol = $this->getHighestColumn();

          // 0. Judul
          $titleRow = $this->writeTitle($sheet, $this->title(), $highestCol);

          // 1. Metadata
          $metaStart = $titleRow + 1;
          $this->writeMetadataSection($sheet, $metadata, $metaCount, $highestCol, $metaStart);
          $metaEnd = $metaStart + ($metaCount > 0 ? $metaCount : 0);

          // 2. Header tabel (1 baris)
          $tableStart = $metaEnd + 2;
          $headerRows = 1;
          $dataStart = $tableStart + $headerRows;
          $lastData = $dataStart + count($this->data) - 1;

          $this->writeHeaders($sheet, $tableStart, $highestCol);
          $this->writeData($sheet, $dataStart, $highestCol);
          $this->styleData($sheet, $dataStart, $lastData, $highestCol);

          // 3. Ringkasan dalam tabel (insert)
          if ($this->summary['include_monthly_summary'] ?? false) {
            $this->insertMonthlySummaries($sheet, $dataStart, $lastData, $highestCol);
          }

          // Auto-filter & auto-size
          $lastDataRow = max($lastData, $dataStart);
          $sheet->setAutoFilter('A' . $tableStart . ':' . $highestCol . $lastDataRow);
          foreach (range('A', $highestCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
          }

          // 4. Tabel Ringkasan + Statistik (menggantikan subtotal)
          $summaryEndRow = $lastData + 1; // fallback
          if ($this->type === 'transactions') {
            $summaryStartRow = $lastData + 2;
            $summaryEndRow = $this->writeSummaryWithStats($sheet, $summaryStartRow, $highestCol);
          }

          // 5. Side blocks (Top Spending + Chart)
          $this->writeSideBlocks($sheet, $tableStart, $highestCol);

          // 6. Footer
          $footerRow = $summaryEndRow + 2;
          $this->writeFooter($sheet, $footerRow, $highestCol);
        },
      ];
    }

    // -------- Title & Metadata ----------

    private function writeTitle(Worksheet $sheet, string $title, string $highestCol): int
    {
      $row = 1;
      $sheet->setCellValue('A' . $row, $title);
      $sheet->mergeCells('A' . $row . ':' . $highestCol . $row);
      $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => '1F4E79']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
      ]);
      return $row;
    }

    private function writeMetadataSection(Worksheet $sheet, array $metadata, int $metaCount, string $highestCol, int $startRow = 1): void
    {
      if ($metaCount === 0) return;

      $sheet->setCellValue('A' . $startRow, 'INFORMASI EKSPOR');
      $sheet->mergeCells('A' . $startRow . ':' . $highestCol . $startRow);
      $sheet->getStyle('A' . $startRow)->applyFromArray($this->infoStyle);

      for ($i = 0; $i < $metaCount; $i++) {
        $r = $startRow + 1 + $i;
        $sheet->setCellValue('A' . $r, $metadata[$i]);
        $sheet->mergeCells('A' . $r . ':' . $highestCol . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($this->metadataStyle);
      }
    }

    // -------- Headers, Data, Style ----------

    private function writeHeaders(Worksheet $sheet, int $startRow, string $highestCol): void
    {
      $headings = $this->headings();
      $col = 'A';
      foreach ($headings as $h) {
        $sheet->setCellValue($col . $startRow, $h);
        $col++;
      }
      $sheet->getStyle('A' . $startRow . ':' . $highestCol . $startRow)->applyFromArray($this->headerStyle);
    }

    private function writeData(Worksheet $sheet, int $startRow, string $highestCol): void
    {
      if (empty($this->data)) {
        $sheet->setCellValue('A' . $startRow, 'Tidak ada data');
        $sheet->mergeCells('A' . $startRow . ':' . $highestCol . $startRow);
        $sheet->getStyle('A' . $startRow)->applyFromArray($this->emptyStyle);
        return;
      }

      $includeDesc = ($this->type === 'transactions' || $this->type === 'transfers')
      ? ($this->summary['include_description'] ?? true)
      : true;

      $row = $startRow;
      foreach ($this->data as $line) {
        $col = 'A';
        $vals = array_values($line);
        if (!$includeDesc) {
          array_pop($vals); // hapus elemen terakhir (Deskripsi)
        }
        foreach ($vals as $val) {
          $display = ($val === 0 || $val === null) ? '0' : $val;
          $sheet->setCellValue($col . $row, $display);
          $col++;
        }
        $row++;
      }
    }

    private function styleData(Worksheet $sheet, int $first, int $last, string $highestCol): void
    {
      $sheet->getStyle('A' . $first . ':' . $highestCol . $last)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
      ]);

      if ($this->type === 'transactions') {
        $sheet->getStyle('E' . $first . ':F' . $last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        for ($r = $first; $r <= $last; $r++) {
          $t = $sheet->getCell('B' . $r)->getValue();
          if ($t === 'Pemasukan') {
            $sheet->getStyle('E' . $r)->getFont()->getColor()->setRGB('28A745');
          } elseif ($t === 'Pengeluaran') {
            $sheet->getStyle('F' . $r)->getFont()->getColor()->setRGB('DC3545');
          }
        }
      } elseif ($this->type === 'transfers') {
        $sheet->getStyle('D' . $first . ':D' . $last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
      } elseif ($this->type === 'budgets') {
        $sheet->getStyle('D' . $first . ':E' . $last)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
      }
    }

    // ---------- In-table summaries ----------

    private function insertMonthlySummaries(Worksheet $sheet, int $dataStartRow, int &$lastDataRow, string $highestCol): void
    {
      $insertions = [];
      $currentMonth = null;
      $totalIncome = 0;
      $totalExpense = 0;
      $dataCount = count($this->data);

      $includeDesc = ($this->type === 'transactions')
      ? ($this->summary['include_description'] ?? true)
      : true;

      // Kolom maksimal saat ini sudah disesuaikan oleh getHighestColumn()
      // Jika includeDesc = false, $highestCol = 'F' (tidak ada kolom G)
      $hasNetColumn = ($highestCol === 'G'); // kolom G hanya ada jika deskripsi disertakan

      for ($i = 0; $i < $dataCount; $i++) {
        $rowData = $this->data[$i];
        $date = \DateTime::createFromFormat('d/m/Y', $rowData['Tanggal'] ?? '');
        $month = $date ? $date->format('Y-m') : null;

        if ($currentMonth === null) {
          $currentMonth = $month;
          $totalIncome = 0;
          $totalExpense = 0;
        }

        if ($month !== $currentMonth) {
          $endIdx = $i - 1;
          $insertRow = $dataStartRow + $endIdx + 1;
          $insertions[] = [
            'row' => $insertRow,
            'income' => $totalIncome,
            'expense' => $totalExpense,
            'monthKey' => $currentMonth,
          ];
          $currentMonth = $month;
          $totalIncome = 0;
          $totalExpense = 0;
        }

        $incomeVal = ChartDataProcessor::parseCurrency($rowData['Pemasukan'] ?? '0');
        $expenseVal = ChartDataProcessor::parseCurrency($rowData['Pengeluaran'] ?? '0');
        $totalIncome += $incomeVal;
        $totalExpense += $expenseVal;
      }

      if ($currentMonth !== null) {
        $endIdx = $dataCount - 1;
        $insertRow = $dataStartRow + $endIdx + 1;
        $insertions[] = [
          'row' => $insertRow,
          'income' => $totalIncome,
          'expense' => $totalExpense,
          'monthKey' => $currentMonth,
        ];
      }

      usort($insertions, fn($a, $b) => $b['row'] - $a['row']);

      foreach ($insertions as $ins) {
        $r = $ins['row'];
        $sheet->insertNewRowBefore($r, 1);

        // Label bulan (merge A..D)
        $sheet->setCellValue('A' . $r, 'Jumlah ' . $ins['monthKey']);
        $sheet->mergeCells('A' . $r . ':D' . $r);

        // Pemasukan (kolom E)
        $sheet->setCellValue('E' . $r, ChartDataProcessor::formatCurrency($ins['income'] ?? 0, $this->summary));
        // Pengeluaran (kolom F)
        $sheet->setCellValue('F' . $r, ChartDataProcessor::formatCurrency($ins['expense'] ?? 0, $this->summary));

        // Net hanya jika ada kolom G (deskripsi disertakan)
        if ($hasNetColumn) {
          $diff = ($ins['income'] ?? 0) - ($ins['expense'] ?? 0);
          $sheet->setCellValue('G' . $r, ChartDataProcessor::formatCurrency($diff, $this->summary));
        }

        // Styling
        $sty = [
          'font' => ['bold' => true,
            'size' => 10],
          'fill' => ['fillType' => Fill::FILL_SOLID,
            'startColor' => ['rgb' => 'E6F0FF']],
          'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        ];
        $sheet->getStyle('A' . $r . ':' . $highestCol . $r)->applyFromArray($sty);

        $sheet->getStyle('A' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('E' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('F' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        if ($hasNetColumn) {
          $sheet->getStyle('G' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        // Warna
        $sheet->getStyle('E' . $r)->getFont()->getColor()->setRGB('28A745');
        $sheet->getStyle('F' . $r)->getFont()->getColor()->setRGB('DC3545');
        if ($hasNetColumn) {
          $diff = ($ins['income'] ?? 0) - ($ins['expense'] ?? 0);
          $sheet->getStyle('G' . $r)->getFont()->getColor()->setRGB($diff >= 0 ? '28A745' : 'DC3545');
        }

        $lastDataRow++;
      }
    }

    // -------- Summary + Stats (kiri) ----------

    private function writeSummaryWithStats(Worksheet $sheet, int $startRow, string $highestCol): int
    {
      $grouped = [];
      foreach ($this->data as $row) {
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
      if (empty($grouped)) return $startRow;

      $totalIncome = array_sum(array_column($grouped, 'income'));
      $totalExpense = array_sum(array_column($grouped, 'expense'));
      $monthCount = count($grouped);
      $avgIncome = $monthCount > 0 ? $totalIncome / $monthCount : 0;
      $avgExpense = $monthCount > 0 ? $totalExpense / $monthCount : 0;
      $ratio = $totalIncome > 0 ? ($totalExpense / $totalIncome) * 100 : 0;

      // Judul
      $sheet->setCellValue('A' . $startRow, 'Ringkasan Bulanan & Statistik');
      $sheet->mergeCells('A' . $startRow . ':' . $highestCol . $startRow);
      $sheet->getStyle('A' . $startRow)->applyFromArray($this->infoStyle);
      $startRow++;

      // Header tabel
      $sheet->setCellValue('A' . $startRow, 'Bulan');
      $sheet->setCellValue('B' . $startRow, 'Pemasukan');
      $sheet->setCellValue('C' . $startRow, 'Pengeluaran');
      $sheet->setCellValue('D' . $startRow, 'Net');
      $sheet->getStyle('A' . $startRow . ':D' . $startRow)->applyFromArray($this->headerStyle);
      $startRow++;

      // Data per bulan
      foreach ($grouped as $item) {
        $net = $item['income'] - $item['expense'];
        $sheet->setCellValue('A' . $startRow, $item['label']);
        $sheet->setCellValue('B' . $startRow, $item['income']);
        $sheet->setCellValue('C' . $startRow, $item['expense']);
        $sheet->setCellValue('D' . $startRow, $net);
        $sheet->getStyle('B' . $startRow)->getFont()->getColor()->setRGB('28A745');
        $sheet->getStyle('C' . $startRow)->getFont()->getColor()->setRGB('DC3545');
        $sheet->getStyle('D' . $startRow)->getFont()->getColor()->setRGB($net >= 0 ? '28A745' : 'DC3545');
        $startRow++;
      }

      // Total
      $sheet->setCellValue('A' . $startRow, 'Total');
      $sheet->setCellValue('B' . $startRow, $totalIncome);
      $sheet->setCellValue('C' . $startRow, $totalExpense);
      $sheet->setCellValue('D' . $startRow, $totalIncome - $totalExpense);
      $sty = [
        'font' => ['bold' => true,
          'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID,
          'startColor' => ['rgb' => 'D9E2F3']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
      ];
      $sheet->getStyle('A' . $startRow . ':D' . $startRow)->applyFromArray($sty);
      $sheet->getStyle('B' . $startRow)->getFont()->getColor()->setRGB('28A745');
      $sheet->getStyle('C' . $startRow)->getFont()->getColor()->setRGB('DC3545');
      $sheet->getStyle('D' . $startRow)->getFont()->getColor()->setRGB(($totalIncome - $totalExpense) >= 0 ? '28A745' : 'DC3545');
      $startRow++;

      // Statistik
      $sheet->setCellValue('A' . $startRow, 'Rata‑rata Pemasukan/Bulan');
      $sheet->setCellValue('B' . $startRow, $avgIncome);
      $sheet->getStyle('B' . $startRow)->getFont()->getColor()->setRGB('28A745');
      $startRow++;

      $sheet->setCellValue('A' . $startRow, 'Rata‑rata Pengeluaran/Bulan');
      $sheet->setCellValue('C' . $startRow, $avgExpense);
      $sheet->getStyle('C' . $startRow)->getFont()->getColor()->setRGB('DC3545');
      $startRow++;

      $sheet->setCellValue('A' . $startRow, 'Rasio Pengeluaran (%)');
      $sheet->setCellValue('D' . $startRow, round($ratio, 1) . '%');
      $startRow++;

      // Border keseluruhan
      $firstRow = $startRow - 7 - count($grouped); // hitung mundur ke judul
      $sheet->getStyle('A' . $firstRow . ':D' . ($startRow - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
      ]);

      // Auto‑size A..D
      foreach (range('A', 'D') as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
      }

      return $startRow - 1; // baris terakhir yang tertulis
    }

    // -------- Side blocks (kanan) ----------

    private function writeSideBlocks(Worksheet $sheet, int $tableStart, string $highestCol): void
    {
      $includeTop5 = $this->summary['include_top5'] ?? false;
      $includeChart = $this->summary['include_chart'] ?? false;
      $includeCategoryExpense = $this->summary['include_category_expense'] ?? false;

      if ($this->type !== 'transactions' || count($this->data) === 0) return;

      $dataEndColIndex = Coordinate::columnIndexFromString($highestCol);
      $blockColIndex = $dataEndColIndex + 2;
      $nextColIndex = $blockColIndex;
      $currentRow = $tableStart;

      if ($includeTop5) {
        $startCol = Coordinate::stringFromColumnIndex($blockColIndex);
        $currentRow = $this->writeTopSpendingTable($sheet, $currentRow, $this->data, $startCol);
        $currentRow++;
        $currentRow = $this->writeTopIncomeTable($sheet, $currentRow, $this->data, $startCol);
        $currentRow++;
      }

      if ($includeCategoryExpense) {
        $currentRow = $this->writeCategoryExpenseTable($sheet, $currentRow, $this->data, Coordinate::stringFromColumnIndex($blockColIndex));
        $currentRow++;
      }

      if ($includeTop5 || $includeCategoryExpense) {
        $nextColIndex = $blockColIndex + 5; // 4 kolom + 1 jarak
      }

      if ($includeChart) {
        $chartStartCol = Coordinate::stringFromColumnIndex($nextColIndex);
        $chartRow = $tableStart;
        $this->addChartToSheet($sheet, $chartRow, $this->data, $chartStartCol);
        $trendChartRow = $chartRow + 20 + 2;
        $this->addTrendChart($sheet, $trendChartRow, $this->data, $chartStartCol);

        if ($includeCategoryExpense) {
          $pieChartRow = $trendChartRow + 20 + 2;
          $this->writeCategoryPieChart($sheet, $pieChartRow, $this->data, $chartStartCol);
        }
      }
    }

    // -------- Top Spending / Income Table ----------

    private function writeTopSpendingTable(Worksheet $sheet, int $startRow, array $data, string $startCol = 'I'): int
    {
      $expenses = array_filter($data, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
      if (empty($expenses)) return $startRow;

      usort($expenses, fn($a, $b) =>
        ChartDataProcessor::parseCurrency($b['Pengeluaran'] ?? '0') <=>
        ChartDataProcessor::parseCurrency($a['Pengeluaran'] ?? '0')
      );
      $top5 = array_slice($expenses, 0, 5);
      $includeDesc = ($this->summary['include_description'] ?? true);

      $colIdx = Coordinate::columnIndexFromString($startCol);
      $colTanggal = $startCol;
      $colKategori = Coordinate::stringFromColumnIndex($colIdx + 1);
      $colJumlah = Coordinate::stringFromColumnIndex($colIdx + 2);
      $colDeskripsi = $includeDesc ? Coordinate::stringFromColumnIndex($colIdx + 3) : null;
      $mergeEndCol = $includeDesc ? $colDeskripsi : $colJumlah;

      $sheet->setCellValue($colTanggal . $startRow, 'Top 5 Pengeluaran');
      $sheet->mergeCells($colTanggal . $startRow . ':' . $mergeEndCol . $startRow);
      $sheet->getStyle($colTanggal . $startRow)->applyFromArray($this->infoStyle);

      $hRow = $startRow + 1;
      $sheet->setCellValue($colTanggal . $hRow, 'Tanggal');
      $sheet->setCellValue($colKategori . $hRow, 'Kategori');
      $sheet->setCellValue($colJumlah . $hRow, 'Jumlah');
      if ($includeDesc) {
        $sheet->setCellValue($colDeskripsi . $hRow, 'Deskripsi');
      }
      $styleRange = $colTanggal . $hRow . ':'. $mergeEndCol . $hRow;
      $sheet->getStyle($styleRange)->applyFromArray($this->headerStyle);

      $row = $hRow + 1;
      foreach ($top5 as $item) {
        $amount = ChartDataProcessor::parseCurrency($item['Pengeluaran'] ?? '0');
        $sheet->setCellValue($colTanggal . $row, $item['Tanggal'] ?? '');
        $sheet->setCellValue($colKategori . $row, $item['Kategori'] ?? '');
        $sheet->setCellValue($colJumlah . $row, ChartDataProcessor::formatCurrency($amount, $this->summary));
        if ($includeDesc) {
          $sheet->setCellValue($colDeskripsi . $row, $item['Deskripsi'] ?? '-');
        }
        $sheet->getStyle($colJumlah . $row)->getFont()->getColor()->setRGB('DC3545');
        $row++;
      }

      $lastRow = $row - 1;
      $borderRange = $colTanggal . $hRow . ':'. $mergeEndCol . $lastRow;
      $sheet->getStyle($borderRange)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
      ]);

      $cols = [$colTanggal,
        $colKategori,
        $colJumlah];
      if ($includeDesc) $cols[] = $colDeskripsi;
      foreach ($cols as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
      }

      return $row;
    }

    private function writeTopIncomeTable(Worksheet $sheet, int $startRow, array $data, string $startCol = 'I'): int
    {
      $incomes = array_filter($data, fn($r) => ($r['Tipe'] ?? '') === 'Pemasukan');
      if (empty($incomes)) return $startRow;

      usort($incomes, fn($a, $b) =>
        ChartDataProcessor::parseCurrency($b['Pemasukan'] ?? '0') <=>
        ChartDataProcessor::parseCurrency($a['Pemasukan'] ?? '0')
      );
      $top5 = array_slice($incomes, 0, 5);
      $includeDesc = ($this->summary['include_description'] ?? true);

      $colIdx = Coordinate::columnIndexFromString($startCol);
      $colTanggal = $startCol;
      $colKategori = Coordinate::stringFromColumnIndex($colIdx + 1);
      $colJumlah = Coordinate::stringFromColumnIndex($colIdx + 2);
      $colDeskripsi = $includeDesc ? Coordinate::stringFromColumnIndex($colIdx + 3) : null;
      $mergeEndCol = $includeDesc ? $colDeskripsi : $colJumlah;

      $sheet->setCellValue($colTanggal . $startRow, 'Top 5 Pemasukan');
      $sheet->mergeCells($colTanggal . $startRow . ':' . $mergeEndCol . $startRow);
      $sheet->getStyle($colTanggal . $startRow)->applyFromArray($this->infoStyle);

      $hRow = $startRow + 1;
      $sheet->setCellValue($colTanggal . $hRow, 'Tanggal');
      $sheet->setCellValue($colKategori . $hRow, 'Kategori');
      $sheet->setCellValue($colJumlah . $hRow, 'Jumlah');
      if ($includeDesc) {
        $sheet->setCellValue($colDeskripsi . $hRow, 'Deskripsi');
      }
      $styleRange = $colTanggal . $hRow . ':'. $mergeEndCol . $hRow;
      $sheet->getStyle($styleRange)->applyFromArray($this->headerStyle);

      $row = $hRow + 1;
      foreach ($top5 as $item) {
        $amount = ChartDataProcessor::parseCurrency($item['Pemasukan'] ?? '0');
        $sheet->setCellValue($colTanggal . $row, $item['Tanggal'] ?? '');
        $sheet->setCellValue($colKategori . $row, $item['Kategori'] ?? '');
        $sheet->setCellValue($colJumlah . $row, ChartDataProcessor::formatCurrency($amount, $this->summary));
        if ($includeDesc) {
          $sheet->setCellValue($colDeskripsi . $row, $item['Deskripsi'] ?? '-');
        }
        $sheet->getStyle($colJumlah . $row)->getFont()->getColor()->setRGB('28A745');
        $row++;
      }

      $lastRow = $row - 1;
      $borderRange = $colTanggal . $hRow . ':'. $mergeEndCol . $lastRow;
      $sheet->getStyle($borderRange)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
      ]);

      $cols = [$colTanggal,
        $colKategori,
        $colJumlah];
      if ($includeDesc) $cols[] = $colDeskripsi;
      foreach ($cols as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
      }

      return $row;
    }

    private function writeCategoryExpenseTable(Worksheet $sheet, int $startRow, array $data, string $startCol = 'I'): int
    {
      // Filter hanya pengeluaran
      $expenses = array_filter($data, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
      if (empty($expenses)) return $startRow;

      // Hitung total per kategori dan jumlah transaksi per kategori
      $catTotals = [];
      $catCounts = [];
      foreach ($expenses as $item) {
        $cat = $item['Kategori'] ?? 'Lainnya';
        $catTotals[$cat] = ($catTotals[$cat] ?? 0) + (float)($item['Pengeluaran'] ?? 0);
        $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
      }

      $totalAll = array_sum($catTotals);
      if ($totalAll <= 0) return $startRow;

      // Urutkan berdasarkan persentase tertinggi
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

      $colIdx = Coordinate::columnIndexFromString($startCol);
      $colKategori = $startCol;
      $colTotal = Coordinate::stringFromColumnIndex($colIdx + 1);
      $colPersen = Coordinate::stringFromColumnIndex($colIdx + 2);
      $colRata = Coordinate::stringFromColumnIndex($colIdx + 3);

      // Period dari data
      $period = '';
      $metadata = $this->summary['metadata'] ?? [];
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

      // Judul
      $title = 'Persentase Kategori Pengeluaran';
      if ($period) {
        $title .= ' (' . $period . ')';
      }
      $sheet->setCellValue($colKategori . $startRow, $title);
      $sheet->mergeCells($colKategori . $startRow . ':' . $colRata . $startRow);
      $sheet->getStyle($colKategori . $startRow)->applyFromArray($this->infoStyle);

      // Header
      $hRow = $startRow + 1;
      $sheet->setCellValue($colKategori . $hRow, 'Kategori');
      $sheet->setCellValue($colTotal . $hRow, 'Total');
      $sheet->setCellValue($colPersen . $hRow, 'Persentase');
      $sheet->setCellValue($colRata . $hRow, 'Rata‑rata');
      $sheet->getStyle($colKategori . $hRow . ':' . $colRata . $hRow)->applyFromArray($this->headerStyle);

      // Data
      $row = $hRow + 1;
      foreach ($sorted as $item) {
        $sheet->setCellValue($colKategori . $row, $item['cat']);
        $sheet->setCellValue($colTotal . $row, ChartDataProcessor::formatCurrency($item['total'], $this->summary));
        $sheet->setCellValue($colPersen . $row, round($item['percentage'], 1) . '%');
        $sheet->setCellValue($colRata . $row, ChartDataProcessor::formatCurrency($item['average'], $this->summary));

        // Warna
        $sheet->getStyle($colTotal . $row)->getFont()->getColor()->setRGB('DC3545');
        $sheet->getStyle($colRata . $row)->getFont()->getColor()->setRGB('DC3545');
        $pct = $item['percentage'];
        if ($pct >= 30) {
          $color = 'DC3545';
        } elseif ($pct >= 10) {
          $color = 'FFC107';
        } else {
          $color = '28A745';
        }
        $sheet->getStyle($colPersen . $row)->getFont()->getColor()->setRGB($color);
        $row++;
      }

      $lastRow = $row - 1;
      $sheet->getStyle($colKategori . $hRow . ':' . $colRata . $lastRow)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
      ]);

      foreach ([$colKategori, $colTotal, $colPersen, $colRata] as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
      }

      return $row;
    }

    // -------- Charts ----------

    private function addChartToSheet(Worksheet $sheet, int $startRow, array $data, string $chartCol = 'B'): void
    {
      $width = $height = 0;
      $path = ChartDataProcessor::createBarChart($data, null, $width, $height);
      if ($path) {
        $this->embedImage($sheet, $path, $chartCol . $startRow, $width, $height);
      }
    }

    private function addTrendChart(Worksheet $sheet, int $startRow, array $data, string $chartCol = 'B'): void
    {
      $width = $height = 0;
      $path = ChartDataProcessor::createTrendChart($data, null, $width, $height);
      if ($path) {
        $this->embedImage($sheet, $path, $chartCol . $startRow, $width, $height);
      }
    }

    private function writeCategoryPieChart(Worksheet $sheet, int $startRow, array $data, string $chartCol = 'B'): void
    {
      $width = $height = 0;
      $path = ChartDataProcessor::createCategoryPieChart($data, null, $width, $height);
      if ($path) {
        $this->embedImage($sheet, $path, $chartCol . $startRow, $width, $height);
      }
    }

    private function embedImage(Worksheet $sheet, string $tempFile, string $coordinate, int $width, int $height): void
    {
      $drawing = new Drawing();
      $drawing->setPath($tempFile);
      $drawing->setCoordinates($coordinate);
      $drawing->setWidth($width);
      $drawing->setHeight($height);
      $drawing->setWorksheet($sheet);

      register_shutdown_function(function () use ($tempFile) {
        if (file_exists($tempFile)) unlink($tempFile);
      });
    }

    // -------- Footer ----------

    private function writeFooter(Worksheet $sheet,
      int $footerRow,
      string $highestCol): void
    {
      $sheet->setCellValue(
        'A' . $footerRow,
        'Generated by ' . config('app.name', 'Laravel') . ' App - ' .
        now()->setTimezone(config('app.timezone'))->format('d M Y H:i')
      );
      $sheet->mergeCells('A' . $footerRow . ':' . $highestCol . $footerRow);
      $sheet->getStyle('A' . $footerRow)->applyFromArray($this->footerStyle);
    }

    // -------- Helpers ----------

    private function getHighestColumn(): string
    {
      $base = match ($this->type) {
        'transactions' => 'G',
        'transfers' => 'E',
        'budgets' => 'G',
      default => 'E',
      };

      if (($this->type === 'transactions' || $this->type === 'transfers') && !($this->summary['include_description'] ?? true)) {
        // Mundur satu kolom
        $base = chr(ord($base) - 1);
      }

      return $base;
    }
  }