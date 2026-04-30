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
  private array $subtotalStyle;
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

    $this->subtotalStyle = [
      'font' => ['bold' => true,
        'size' => 11],
      'fill' => ['fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'D9E2F3']],
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

    public function registerEvents(): array
    {
      return [
        AfterSheet::class => function (AfterSheet $event) {
          $sheet = $event->sheet->getDelegate();
          $metadata = $this->summary['metadata'] ?? [];
          $metaCount = count($metadata);
          $highestCol = $this->getHighestColumn();

          $titleRow = $this->writeTitle($sheet, $this->title(), $highestCol);

          $metaStart = $titleRow + 1;
          $this->writeMetadataSection($sheet, $metadata, $metaCount, $highestCol, $metaStart);

          $metaEnd = $metaStart + ($metaCount > 0 ? $metaCount : 0);
          if ($metaCount === 0) {
            $metaEnd = $metaStart;
          } else {
            $metaEnd = $metaStart + $metaCount - 1;
          }

          $tableStart = $metaEnd + 2;
          $headerRows = $this->headerRowCount();
          $dataStart = $tableStart + $headerRows;
          $lastData = $dataStart + count($this->data) - 1;

          $this->writeHeaders($sheet, $tableStart, $highestCol);
          $this->writeData($sheet, $dataStart, $highestCol);
          $this->styleData($sheet, $dataStart, $lastData, $highestCol);

          if ($this->summary['include_monthly_summary'] ?? false) {
            $this->insertMonthlySummaries($sheet, $dataStart, $lastData, $highestCol);
          }

          $lastDataRow = max($lastData, $dataStart);
          $sheet->setAutoFilter('A' . $tableStart . ':' . $highestCol . $lastDataRow);
          foreach (range('A', $highestCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
          }

          $subStart = $lastData + 2;
          $this->writeSubtotal($sheet, $subStart, $highestCol);
          $subDetailRows = $this->subtotalDetailRows();
          $subEnd = $subStart + $subDetailRows;

          $footerRow = $subEnd + 2;
          $this->writeFooter($sheet, $footerRow, $highestCol);

          $this->writeSideBlocks($sheet, $tableStart, $highestCol);
        },
      ];
    }

    private function writeMetadataSection(Worksheet $sheet, array $metadata, int $metaCount, string $highestCol, int $startRow = 1): void
    {
      if ($metaCount === 0) return;

      $sheet->setCellValue('A'. $startRow, 'INFORMASI EKSPOR');
      $sheet->mergeCells('A'. $startRow .':' . $highestCol . $startRow);
      $sheet->getStyle('A'. $startRow)->applyFromArray($this->infoStyle);

      for ($i = 0; $i < $metaCount; $i++) {
        $r = $startRow + 1 + $i;
        $sheet->setCellValue('A' . $r, $metadata[$i]);
        $sheet->mergeCells('A' . $r . ':' . $highestCol . $r);
        $sheet->getStyle('A' . $r)->applyFromArray($this->metadataStyle);
      }
    }

    private function writeFooter(Worksheet $sheet, int $footerRow, string $highestCol): void
    {
      $sheet->setCellValue('A' . $footerRow,
        'Generated by ' . config('app.name', 'Laravel') . ' App - ' . now()->setTimezone(config('app.timezone'))->format('d M Y H:i'));
      $sheet->mergeCells('A' . $footerRow . ':' . $highestCol . $footerRow);
      $sheet->getStyle('A' . $footerRow)->applyFromArray($this->footerStyle);
    }

    private function writeSideBlocks(Worksheet $sheet, int $tableStart, string $highestCol): void
    {
      $includeMonthly = $this->summary['include_monthly_summary'] ?? false;
      $includeTopSpending = $this->summary['include_top_spending'] ?? false;
      $includeChart = $this->summary['include_chart'] ?? false;

      if ($this->type !== 'transactions' || count($this->data) === 0) return;
      if (!$includeMonthly && !$includeTopSpending && !$includeChart) return;

      $dataEndColIndex = Coordinate::columnIndexFromString($highestCol);
      $blockColIndex = $dataEndColIndex + 2;
      $nextColIndex = $blockColIndex;
      $currentRow = $tableStart;

      if ($includeMonthly) {
        $startCol = Coordinate::stringFromColumnIndex($blockColIndex);
        $currentRow = $this->writeMonthlySummaryTable($sheet, $currentRow, $this->data, $startCol);
        $currentRow++;
      }

      if ($includeTopSpending) {
        $startCol = Coordinate::stringFromColumnIndex($blockColIndex);
        $currentRow = $this->writeTopSpendingTable($sheet, $currentRow, $this->data, $startCol);
        $currentRow++;
      }

      if ($includeMonthly || $includeTopSpending) {
        $nextColIndex = $blockColIndex + 5;
      }

      if ($includeChart) {
        $chartStartCol = Coordinate::stringFromColumnIndex($nextColIndex);
        $chartRow = $tableStart;
        $this->addChartToSheet($sheet, $chartRow, $this->data, $chartStartCol);
        $trendChartRow = $chartRow + 20 + 2;
        $this->addTrendChart($sheet, $trendChartRow, $this->data, $chartStartCol);
      }
    }

    // --------------- Header & Data ---------------

    private function writeHeaders(Worksheet $sheet, int $startRow, string $highestCol): void
    {
      if ($this->type === 'transactions') {
        $sheet->mergeCells('A' . $startRow . ':A' . ($startRow + 1));
        $sheet->setCellValue('A' . $startRow, 'Tanggal');
        $sheet->mergeCells('B' . $startRow . ':B' . ($startRow + 1));
        $sheet->setCellValue('B' . $startRow, 'Tipe');
        $sheet->mergeCells('C' . $startRow . ':C' . ($startRow + 1));
        $sheet->setCellValue('C' . $startRow, 'Kategori');
        $sheet->mergeCells('D' . $startRow . ':D' . ($startRow + 1));
        $sheet->setCellValue('D' . $startRow, 'Dompet');
        $sheet->mergeCells('E' . $startRow . ':F' . $startRow);
        $sheet->setCellValue('E' . $startRow, 'Amount');
        $sheet->mergeCells('G' . $startRow . ':G' . ($startRow + 1));
        $sheet->setCellValue('G' . $startRow, 'Deskripsi');
        $sheet->getStyle('A' . $startRow . ':G' . ($startRow + 1))->applyFromArray($this->headerStyle);
        $sheet->setCellValue('E' . ($startRow + 1), 'Pemasukan');
        $sheet->setCellValue('F' . ($startRow + 1), 'Pengeluaran');
      } else {
        $headings = $this->headings();
        $col = 'A';
        foreach ($headings as $h) {
          $sheet->setCellValue($col . $startRow, $h);
          $col++;
        }
        $sheet->getStyle('A' . $startRow . ':' . $highestCol . $startRow)->applyFromArray($this->headerStyle);
      }
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

    // --------------- In‑table summaries ---------------

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
        if ($diff >= 0) {
          $sheet->getStyle('G' . $r)->getFont()->getColor()->setRGB('28A745');
        } else {
          $sheet->getStyle('G' . $r)->getFont()->getColor()->setRGB('DC3545');
        }

        $lastDataRow++;
      }
    }

    private function writeSubtotal(Worksheet $sheet, int $startRow, string $highestCol): void
    {
      $f = $this->summary;
      $fmt = fn($v) => ChartDataProcessor::formatCurrency($v, $f);

      $sheet->setCellValue('A' . $startRow, 'SUBTOTAL');
      $sheet->mergeCells('A' . $startRow . ':' . $highestCol . $startRow);
      $sheet->getStyle('A' . $startRow . ':' . $highestCol . $startRow)->applyFromArray($this->subtotalStyle);

      $d = $startRow + 1;
      switch ($this->type) {
      case 'transactions':
        $this->writeSubtotalRow($sheet, $d++, $highestCol, 'Pemasukan: ' . $fmt($f['total_income']));
        $this->writeSubtotalRow($sheet, $d++, $highestCol, 'Pengeluaran: ' . $fmt($f['total_expense']));
        $this->writeSubtotalRow($sheet, $d++, $highestCol, 'Net: ' . $fmt($f['net']));
        break;
      case 'transfers':
        $this->writeSubtotalRow($sheet, $d++, $highestCol, 'Total Transfer: ' . $fmt($f['total']));
        break;
      case 'budgets':
        $sheet->setCellValue('D' . $d, 'Total Limit: ' . $fmt($f['total_limit']));
        $sheet->setCellValue('E' . $d, 'Total Pengeluaran: ' . $fmt($f['total_spent']));
        $sheet->getStyle('A' . $d . ':' . $highestCol . $d)->applyFromArray($this->subtotalStyle);
        $sheet->getStyle('D' . $d)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle('E' . $d)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $d++;
        $sheet->setCellValue('D' . $d, 'Sisa: ' . $fmt($f['remaining']));
        $sheet->getStyle('A' . $d . ':' . $highestCol . $d)->applyFromArray($this->subtotalStyle);
        $sheet->getStyle('D' . $d)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        break;
      }
    }

    private function writeSubtotalRow(Worksheet $sheet, int $row, string $highestCol, string $text): void
    {
      $sheet->setCellValue('A' . $row, $text);
      $sheet->mergeCells('A' . $row . ':' . $highestCol . $row);
      $sheet->getStyle('A' . $row . ':' . $highestCol . $row)->applyFromArray(
        $this->subtotalStyle + ['alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT]]
      );
    }

    // --------------- Side blocks ---------------

    private function writeMonthlySummaryTable(Worksheet $sheet, int $startRow, array $data, string $startCol = 'I'): int
    {
      $grouped = [];
      foreach ($data as $row) {
        $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
        if (!$date) continue;
        $monthKey = $date->format('Y-m');
        if (!isset($grouped[$monthKey])) {
          $grouped[$monthKey] = [
            'income' => 0,
            'expense' => 0,
            'label' => $date->format('M Y'),
          ];
        }
        $grouped[$monthKey]['income'] += ChartDataProcessor::parseCurrency($row['Pemasukan'] ?? '0');
        $grouped[$monthKey]['expense'] += ChartDataProcessor::parseCurrency($row['Pengeluaran'] ?? '0');
      }
      ksort($grouped);

      if (empty($grouped)) {
        return $startRow;
      }

      $colIndex = Coordinate::columnIndexFromString($startCol);
      $colA = $startCol;
      $colB = Coordinate::stringFromColumnIndex($colIndex + 1);
      $colC = Coordinate::stringFromColumnIndex($colIndex + 2);
      $colD = Coordinate::stringFromColumnIndex($colIndex + 3);

      $sheet->setCellValue($colA . $startRow, 'Ringkasan Bulanan');
      $sheet->mergeCells($colA . $startRow . ':' . $colD . $startRow);
      $sheet->getStyle($colA . $startRow)->applyFromArray($this->infoStyle);

      $headerRow = $startRow + 1;
      $sheet->setCellValue($colA . $headerRow, 'Bulan');
      $sheet->setCellValue($colB . $headerRow, 'Pemasukan');
      $sheet->setCellValue($colC . $headerRow, 'Pengeluaran');
      $sheet->setCellValue($colD . $headerRow, 'Net');
      $sheet->getStyle($colA . $headerRow . ':' . $colD . $headerRow)->applyFromArray($this->headerStyle);

      $row = $headerRow + 1;
      foreach ($grouped as $item) {
        $net = $item['income'] - $item['expense'];
        $sheet->setCellValue($colA . $row, $item['label']);
        $sheet->setCellValue($colB . $row, ChartDataProcessor::formatCurrency($item['income'], $this->summary));
        $sheet->setCellValue($colC . $row, ChartDataProcessor::formatCurrency($item['expense'], $this->summary));
        $sheet->setCellValue($colD . $row, ChartDataProcessor::formatCurrency($net, $this->summary));

        $sheet->getStyle($colB . $row)->getFont()->getColor()->setRGB('28A745');
        $sheet->getStyle($colC . $row)->getFont()->getColor()->setRGB('DC3545');
        $sheet->getStyle($colD . $row)->getFont()->getColor()->setRGB($net >= 0 ? '28A745' : 'DC3545');
        $row++;
      }

      $totalRow = $row;
      $totalIncome = array_sum(array_column($grouped, 'income'));
      $totalExpense = array_sum(array_column($grouped, 'expense'));
      $totalNet = $totalIncome - $totalExpense;

      $sheet->setCellValue($colA . $totalRow, 'Total');
      $sheet->setCellValue($colB . $totalRow, ChartDataProcessor::formatCurrency($totalIncome, $this->summary));
      $sheet->setCellValue($colC . $totalRow, ChartDataProcessor::formatCurrency($totalExpense, $this->summary));
      $sheet->setCellValue($colD . $totalRow, ChartDataProcessor::formatCurrency($totalNet, $this->summary));

      $sheet->getStyle($colA . $totalRow . ':' . $colD . $totalRow)->applyFromArray([
        'font' => ['bold' => true, 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2F3']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
      ]);
      $sheet->getStyle($colB . $totalRow)->getFont()->getColor()->setRGB('28A745');
      $sheet->getStyle($colC . $totalRow)->getFont()->getColor()->setRGB('DC3545');
      $sheet->getStyle($colD . $totalRow)->getFont()->getColor()->setRGB($totalNet >= 0 ? '28A745' : 'DC3545');

      $row++;
      $lastRow = $row - 1;
      $sheet->getStyle($colA . $headerRow . ':' . $colD . $lastRow)->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'font' => ['size' => 10],
      ]);

      foreach ([$colA, $colB, $colC, $colD] as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
      }

      return $row;
    }

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

    // --------------- Charts ---------------

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

    // --------------- Misc ---------------

    private function getHighestColumn(): string
    {
      return match ($this->type) {
        'transactions' => 'G',
        'transfers' => 'E',
        'budgets' => 'G',
      default => 'E',
      };
    }

    private function headerRowCount(): int
    {
      return $this->type === 'transactions' ? 2 : 1;
    }

    private function subtotalDetailRows(): int
    {
      return match ($this->type) {
        'transactions' => 3,
        'transfers' => 1,
        'budgets' => 2,
      default => 1,
      };
    }

    private function writeTitle(Worksheet $sheet,
      string $title,
      string $highestCol): int
    {
      $row = 1;
      $sheet->setCellValue('A' . $row,
        $title);
      $sheet->mergeCells('A' . $row . ':' . $highestCol . $row);
      $sheet->getStyle('A' . $row)->applyFromArray([
        'font' => ['bold' => true,
          'size' => 16,
          'color' => ['rgb' => '1F4E79']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
      ]);
      return $row;
    }
  }