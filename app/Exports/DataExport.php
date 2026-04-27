<?php

namespace Modules\FinTech\Exports;

use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize:
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class DataExport implements WithHeadings, WithStyles, ShouldAutoSize, WithEvents, WithTitle
{
  protected string $type;
  protected array $data;
  protected array $summary;

  public function __construct(string $type, array $data, array $summary) {
    $this->type = $type;
    $this->data = $data;
    $this->summary = $summary;
  }

  public function headings(): array
  {
    if (empty($this->data)) return [];

    if ($this->type === 'transactions') {
      // Header dua tingkat
      return [
        [
          '',
          '',
          '',
          '',
          'Amount',
          '',
          ''
        ],
        // baris1
        [
          'Tanggal',
          'Tipe',
          'Kategori',
          'Dompet',
          'Pemasukan',
          'Pengeluaran',
          'Deskripsi'
        ],
        // baris2
      ];
    }

    // Untuk transfer/budgets, heading biasa
    return [array_keys($this->data[0])];
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
      // Style untuk header akan kita tangani manual di AfterSheet
    }

    public function registerEvents(): array
    {
      return [
        AfterSheet::class => function (AfterSheet $event) {
          $sheet = $event->sheet->getDelegate();
          $metadata = $this->summary['metadata'] ?? [];
          $metaCount = count($metadata);
          $highestColumn = $this->type === 'transactions' ? 'G' : $sheet->getHighestColumn();

          // ===== 1. Tulis metadata =====
          if ($metaCount > 0) {
            $sheet->setCellValue('A1', 'INFORMASI EKSPOR');
            $sheet->mergeCells('A1:' . $highestColumn . '1');
            $sheet->getStyle('A1')->applyFromArray([
              'font' => ['bold' => true, 'size' => 13, 'color' => ['rgb' => '1F4E79']],
            ]);

            for ($i = 0; $i < $metaCount; $i++) {
              $row = $i + 2;
              $sheet->setCellValue('A' . $row, $metadata[$i]);
              $sheet->mergeCells('A' . $row . ':' . $highestColumn . $row);
              $sheet->getStyle('A' . $row)->applyFromArray([
                'font' => ['size' => 10, 'italic' => true],
              ]);
            }
          }

          // ===== 2. Tentukan posisi header tabel =====
          $tableHeaderRow = $metaCount + 2; // setelah metadata + 1 baris kosong

          if ($this->type === 'transactions') {
            // Header tingkat 1: "Amount" di merge
            $sheet->mergeCells('E' . $tableHeaderRow . ':F' . $tableHeaderRow);
            $sheet->setCellValue('A' . $tableHeaderRow, '');
            $sheet->setCellValue('B' . $tableHeaderRow, '');
            $sheet->setCellValue('C' . $tableHeaderRow, '');
            $sheet->setCellValue('D' . $tableHeaderRow, '');
            $sheet->setCellValue('E' . $tableHeaderRow, 'Amount');
            $sheet->setCellValue('G' . $tableHeaderRow, '');

            $sheet->getStyle('A' . $tableHeaderRow . ':G' . $tableHeaderRow)->applyFromArray([
              'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
              'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F81BD']],
              'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            $headerRow2 = $tableHeaderRow + 1;
            $subHeaders = ['Tanggal',
              'Tipe',
              'Kategori',
              'Dompet',
              'Pemasukan',
              'Pengeluaran',
              'Deskripsi'];
            $col = 'A';
            foreach ($subHeaders as $text) {
              $sheet->setCellValue($col . $headerRow2, $text);
              $col++;
            }
            $sheet->getStyle('A' . $headerRow2 . ':G' . $headerRow2)->applyFromArray([
              'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
              'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F81BD']],
              'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ]);

            // Batas border header
            $sheet->getStyle('A' . $tableHeaderRow . ':G' . $headerRow2)->applyFromArray([
              'borders' => [
                'outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']],
              ],
            ]);

            $dataStartRow = $headerRow2 + 1;
          } else {
            // Heading biasa 1 baris
            $headings = array_keys($this->data[0]);
            $col = 'A';
            foreach ($headings as $text) {
              $sheet->setCellValue($col . $tableHeaderRow, $text);
              $col++;
            }
            $sheet->getStyle('A' . $tableHeaderRow . ':' . $highestColumn . $tableHeaderRow)->applyFromArray([
              'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
              'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4F81BD']],
              'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
              'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            $dataStartRow = $tableHeaderRow + 1;
          }

          // ===== 3. Tulis data =====
          $row = $dataStartRow;
          foreach ($this->data as $dataRow) {
            $col = 'A';
            foreach ($dataRow as $value) {
              $sheet->setCellValue($col . $row, $value);
              $col++;
            }
            $sheet->getStyle('A' . $row . ':' . $highestColumn . $row)->applyFromArray([
              'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]],
            ]);
            $row++;
          }
          $lastDataRow = $row - 1;

          // ===== 4. Pewarnaan =====
          if ($this->type === 'transactions') {
            $colPemasukan = 'E';
            $colPengeluaran = 'F';
            for ($r = $dataStartRow; $r <= $lastDataRow; $r++) {
              $pemasukan = $sheet->getCell($colPemasukan . $r)->getValue();
              $pengeluaran = $sheet->getCell($colPengeluaran . $r)->getValue();
              if ($pemasukan !== '-') {
                $sheet->getStyle($colPemasukan . $r)->getFont()->getColor()->setRGB('28A745');
              }
              if ($pengeluaran !== '-') {
                $sheet->getStyle($colPengeluaran . $r)->getFont()->getColor()->setRGB('DC3545');
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