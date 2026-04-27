<?php

namespace Modules\FinTech\Exports;

use Maatwebsite\Excel\Concerns\ {
  FromArray,
  WithHeadings,
  WithStyles,
  ShouldAutoSize,
  WithEvents,
  WithTitle
};
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\ {
  Border,
  Fill,
  Font,
  Alignment
};

class DataExport implements FromArray, WithHeadings, WithStyles, ShouldAutoSize, WithEvents, WithTitle
{
  protected string $type;
  protected array $data;
  protected array $summary; // termasuk aturan format & subtotal

  public function __construct(string $type, array $data, array $summary) {
    $this->type = $type;
    $this->data = $data;
    $this->summary = $summary;
  }

  public function array(): array
  {
    return $this->data;
  }

  public function headings(): array
  {
    if (empty($this->data)) return [];
    return array_keys($this->data[0]);
  }

  public function title(): string
  {
    return match ($this->type) {
      'transactions' => 'Transaksi',
      'transfers' => 'Transfer',
      'budgets' => 'Budget',
      default => 'Data'
      };
    }

    public function styles(Worksheet $sheet) {
      // Tentukan range header
      $highestColumn = $sheet->getHighestColumn();
      $headerRange = 'A1:' . $highestColumn . '1';

      // Style header: bold, putih, latar biru, border
      $sheet->getStyle($headerRange)->applyFromArray([
        'font' => [
          'bold' => true,
          'color' => ['rgb' => 'FFFFFF'],
          'size' => 11,
        ],
        'fill' => [
          'fillType' => Fill::FILL_SOLID,
          'startColor' => ['rgb' => '4F81BD'],
        ],
        'borders' => [
          'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000'],
          ],
        ],
        'alignment' => [
          'horizontal' => Alignment::HORIZONTAL_CENTER,
          'vertical' => Alignment::VERTICAL_CENTER,
        ],
      ]);
    }

    public function registerEvents(): array
    {
      return [
        AfterSheet::class => function (AfterSheet $event) {
          $sheet = $event->sheet->getDelegate();
          $meta = $this->summary['metadata'] ?? [];
          $metaCount = count($meta);
          $highestColumn = $sheet->getHighestColumn();

          // ===== 1. Tulis metadata =====
          if ($metaCount > 0) {
            $sheet->setCellValue('A1', 'INFORMASI EKSPOR');
            $sheet->mergeCells('A1:' . $highestColumn . '1');
            $sheet->getStyle('A1')->applyFromArray([
              'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1F4E79']],
            ]);

            for ($i = 0; $i < $metaCount; $i++) {
              $row = $i + 2;
              $sheet->setCellValue('A' . $row, $meta[$i]);
              $sheet->mergeCells('A' . $row . ':' . $highestColumn . $row);
              $sheet->getStyle('A' . $row)->applyFromArray([
                'font' => ['size' => 10, 'italic' => true],
              ]);
            }
          }

          // ===== 2. Tentukan posisi header tabel =====
          $tableHeaderRow = $metaCount + 2; // setelah metadata + 1 baris kosong
          $dataStartRow = $tableHeaderRow + 1;

          // Tulis header tabel (headings) – harus dipindahkan ke baris yang tepat
          $headings = $this->headings();
          $colIndex = 'A';
          foreach ($headings as $heading) {
            $sheet->setCellValue($colIndex . $tableHeaderRow, $heading);
            $colIndex++;
          }

          // Style header tabel
          $headerRange = 'A' . $tableHeaderRow . ':' . $highestColumn . $tableHeaderRow;
          $sheet->getStyle($headerRange)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F81BD']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
          ]);

          // ===== 3. Border data =====
          $lastDataRow = $dataStartRow + count($this->data) - 1;
          $dataRange = 'A' . $dataStartRow . ':' . $highestColumn . $lastDataRow;
          $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
          ]);

          // ===== 4. Pewarnaan Jumlah (transactions) =====
          if ($this->type === 'transactions') {
            $colTipe = 'B';
            $colJumlah = 'E';
            for ($row = $dataStartRow; $row <= $lastDataRow; $row++) {
              $tipe = $sheet->getCell($colTipe . $row)->getValue();
              $jumlahCell = $sheet->getCell($colJumlah . $row);
              if ($tipe === 'Pemasukan') {
                $jumlahCell->getStyle()->getFont()->getColor()->setRGB('28A745');
              } elseif ($tipe === 'Pengeluaran') {
                $jumlahCell->getStyle()->getFont()->getColor()->setRGB('DC3545');
              }
            }
          }

          // ===== 5. Subtotal =====
          $subStartRow = $lastDataRow + 2; // beri jarak 1 baris
          $format = $this->summary; // aturan format

          // Helper closure untuk format uang
          $fmtNumber = function ($value) use ($format) {
            return number_format(
              $value,
              $format['precision'],
              $format['decimal_mark'],
              $format['thousands_separator']
            );
          };
          $fmtCurrency = function ($value) use ($format, $fmtNumber) {
            $num = $fmtNumber($value);
            return $format['symbol_first']
            ? $format['symbol'] . ' ' . $num
            : $num . ' ' . $format['symbol'];
          };

          // Label SUBTOTAL
          $sheet->setCellValue('A' . $subStartRow, 'SUBTOTAL');
          $sheet->mergeCells('A' . $subStartRow . ':D' . $subStartRow);
          $sheet->getStyle('A' . $subStartRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E2F3']],
            'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
          ]);

          $colJumlah = 'E'; // default untuk transaksi
          $row = $subStartRow;

          // Isi sesuai tipe
          switch ($this->type) {
          case 'transactions':
            $sheet->setCellValue($colJumlah . $row, 'Pemasukan: ' . $fmtCurrency($this->summary['total_income']));
            $row++;
            $sheet->setCellValue($colJumlah . $row, 'Pengeluaran: ' . $fmtCurrency($this->summary['total_expense']));
            $row++;
            $sheet->setCellValue($colJumlah . $row, 'Net: ' . $fmtCurrency($this->summary['net']));
            break;

          case 'transfers':
            $colJumlah = 'D'; // kolom Jumlah di transfer adalah D
            $sheet->setCellValue($colJumlah . $row, 'Total Transfer: ' . $fmtCurrency($this->summary['total']));
            break;

          case 'budgets':
            // Kolom Limit di E, Pengeluaran di F, bisa kita taruh di E/F
            $colLimit = 'E';
            $colSpent = 'F';
            $sheet->setCellValue($colLimit . $row, 'Total Limit: ' . $fmtCurrency($this->summary['total_limit']));
            $sheet->setCellValue($colSpent . $row, 'Total Pengeluaran: ' . $fmtCurrency($this->summary['total_spent']));
            $row++;
            $sheet->setCellValue($colLimit . $row, 'Sisa: ' . $fmtCurrency($this->summary['remaining']));
            break;
          }

          // Styling subtotal area
          $subEndRow = $row;
          $subRange = 'A' . $subStartRow . ':' . $highestColumn . $subEndRow;
          $sheet->getStyle($subRange)->applyFromArray([
            'borders' => [
              'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
              ],
            ],
          ]);

          // ===== 6. Footer =====
          $footerRow = $subEndRow + 2;
          $sheet->setCellValue('A' . $footerRow, 'Generated by VickyServer App - ' . now()->format('d M Y H:i'));
          $sheet->mergeCells('A' . $footerRow . ':E' . $footerRow);
          $sheet->getStyle('A' . $footerRow)->applyFromArray([
            'font' => ['italic' => true, 'color' => ['rgb' => '888888'], 'size' => 10],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
          ]);
        },
      ];
    }
  }