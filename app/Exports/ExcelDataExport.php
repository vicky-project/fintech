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

  private array $headerStyle;
  private array $footerStyle;
  private array $infoStyle;
  private array $metadataStyle;
  private array $emptyStyle;

  public function __construct(string $type, array $data, array $summary) {
    $this->type = $type;
    $this->data = $data;
    $this->summary = $summary;

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
    if (!empty($this->data) && is_array($this->data[0])) {
      return array_keys($this->data[0]);
    }
    return match ($this->type) {
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
    }

    public function title(): string
    {
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
          $metaEnd = $metaStart + ($metaCount > 0 ? $metaCount - 1 : 0);

          // 2. Header tabel (1 baris)
          $tableStart = $metaEnd + 3;
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

      $row = $startRow;
      foreach ($this->data as $line) {
        $col = 'A';
        foreach ($line as $val) {
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

        $sheet->setCellValue('A' . $r, 'Jumlah ' . $ins['monthKey']);
        $sheet->mergeCells('A' . $r . ':D' . $r);
        $sheet->setCellValue('E' . $r, ChartDataProcessor::formatCurrency($ins['income'] ?? 0, $this->summary));
        $sheet->setCellValue('F' . $r, ChartDataProcessor::formatCurrency($ins['expense'] ?? 0, $this->summary));
        $diff = ($ins['income'] ?? 0) - ($ins['expense'] ?? 0);
        $sheet->setCellValue('G' . $r, ChartDataProcessor::formatCurrency($diff, $this->summary));

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
        $sheet->getStyle('G' . $r)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $sheet->getStyle('E' . $r)->getFont()->getColor()->setRGB('28A745');
        $sheet->getStyle('F' . $r)->getFont()->getColor()->setRGB('DC3545');
        $sheet->getStyle('G' . $r)->getFont()->getColor()->setRGB($diff >= 0 ? '28A745' : 'DC3545');

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
      $includeTopSpending = $this->summary['include_top_spending'] ?? false;
      $includeChart = $this->summary['include_chart'] ?? false;

      if ($this->type !== 'transactions' || count($this->data) === 0) return;
      if (!$includeTopSpending && !$includeChart) return;

      $dataEndColIndex = Coordinate::columnIndexFromString($highestCol);
      $blockColIndex = $dataEndColIndex + 2;
      $nextColIndex = $blockColIndex;
      $currentRow = $tableStart;

      if ($includeTopSpending) {
        $startCol = Coordinate::stringFromColumnIndex($blockColIndex);
        $currentRow = $this->writeTopSpendingTable($sheet, $currentRow, $this->data, $startCol);
        $nextColIndex = $blockColIndex + 5; // 4 kolom + 1 jarak
      }

      if ($includeChart) {
        $chartStartCol = Coordinate::stringFromColumnIndex($nextColIndex);
        $chartRow = $tableStart;
        $this->addChartToSheet($sheet, $chartRow, $this->data, $chartStartCol);
        $trendChartRow = $chartRow + 20 + 2;
        $this->addTrendChart($sheet, $trendChartRow, $this->data, $chartStartCol);
      }
    }

    // -------- Top Spending Table ----------

    private function writeTopSpendingTable(Worksheet $sheet, int $startRow, array $data, string $startCol = 'I'): int
    {
      $expenses = array_filter($data, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
      if (empty($expenses)) return $startRow;

      usort($expenses, fn($a, $b) =>
        ChartDataProcessor::parseCurrency($b['Pengeluaran'] ?? '0') <=>
        ChartDataProcessor::parseCurrency($a['Pengeluaran'] ?? '0')
      );
      $top5 = array_slice($expenses, 0, 5);

      $colIdx = Coordinate::columnIndexFromString($startCol);
      $colTanggal = $startCol;
      $colKategori = Coordinate::stringFromColumnIndex($colIdx + 1);
      $colJumlah = Coordinate::stringFromColumnIndex($colIdx + 2);
      $colDeskripsi = Coordinate::stringFromColumnIndex($colIdx + 3);

      $sheet->setCellValue($colTanggal . $startRow, 'Top 5 Pengeluaran');
      $sheet->mergeCells($colTanggal . $startRow . ':' . $colDeskripsi . $startRow);
      $sheet->getStyle($colTanggal . $startRow)->applyFromArray($this->infoStyle);

      $hRow = $startRow + 1;
      $sheet->setCellValue($colTanggal . $hRow, 'Tanggal');
      $sheet->setCellValue($colKategori . $hRow, 'Kategori');
      $sheet->setCellValue($colJumlah . $hRow, 'Jumlah');
      $sheet->setCellValue($colDeskripsi . $hRow, 'Deskripsi');
      $sheet->getStyle($colTanggal . $hRow . ':' . $colDeskripsi . $hRow)->applyFromArray($this->headerStyle);

      $row = $hRow + 1;
      foreach ($top5 as $item) {
        $amount = ChartDataProcessor::parseCurrency($item['Pengeluaran'] ?? '0');
        $sheet->setCellValue($colTanggal . $row, $item['Tanggal'] ?? '');
        $sheet->setCellValue($colKategori . $row, $item['Kategori'] ?? '');
        $sheet->setCellValue($colJumlah . $row, ChartDataProcessor::formatCurrency($amount, $this->summary));
        $sheet->setCellValue($colDeskripsi . $row, $item['Deskripsi'] ?? '-');
        $sheet->getStyle($colJumlah . $row)->getFont()->getColor()->setRGB('DC3545');
        $row++;
      }

      $lastRow = $row - 1;
      $sheet->getStyle($colTanggal . $hRow . ':' . $colDeskripsi . $lastRow)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
      ]);

      foreach ([$colTanggal, $colKategori, $colJumlah, $colDeskripsi] as $c) {
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
      return match ($this->type) {
        'transactions' => 'G',
        'transfers' => 'E',
        'budgets' => 'G',
      default => 'E',
      };
    }
  }