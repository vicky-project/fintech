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
  protected array $summary;

  public function __construct(string $type, array $data, array $summary) {
    $this->type = $type;
    $this->data = $data;
    $this->summary = $summary;
  }

  /**
  * Data yang akan diekspor.
  */
  public function array(): array
  {
    return $this->data;
  }

  /**
  * Judul kolom diambil dari key array pertama.
  */
  public function headings(): array
  {
    if (empty($this->data)) return [];
    return array_keys($this->data[0]);
  }

  /**
  * Nama sheet.
  */
  public function title(): string
  {
    return match ($this->type) {
      'transactions' => 'Transaksi',
      'transfers' => 'Transfer',
      'budgets' => 'Budget',
      default => 'Data'
      };
    }

    /**
    * Style header dan border data.
    */
    public function styles(Worksheet $sheet) {
      // Tentukan range header
      $highestColumn = $sheet->getHighestColumn();
      $headerRange = 'A1:' . $highestColumn . '1';

      // Style header: bold, putih, background biru, border
      $sheet->getStyle($headerRange)->applyFromArray([
        'font' => [
          'bold' => true,
          'color' => ['rgb' => 'FFFFFF'],
        ],
        'fill' => [
          'fillType' => Fill::FILL_SOLID,
          'startColor' => ['rgb' => '4F81BD'], // biru profesional
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

      // Border untuk semua sel data
      $dataRowCount = count($this->data);
      if ($dataRowCount > 0) {
        $lastRow = $dataRowCount + 1;
        $dataRange = 'A2:' . $highestColumn . $lastRow;
        $sheet->getStyle($dataRange)->applyFromArray([
          'borders' => [
            'allBorders' => [
              'borderStyle' => Border::BORDER_THIN,
              'color' => ['rgb' => '000000'],
            ],
          ],
        ]);
      }
    }

    /**
    * Tambahkan footer di bawah data.
    */
    public function registerEvents(): array
    {
      return [
        AfterSheet::class => function (AfterSheet $event) {
          $sheet = $event->sheet->getDelegate();
          $dataCount = count($this->data);
          $startRow = $dataCount + 1; // baris setelah data terakhir (kosong)
          $highestColumn = $sheet->getHighestColumn();

          // --- Subtotal Section ---
          $sheet->setCellValue('A' . $startRow, 'SUBTOTAL');
          $sheet->mergeCells('A' . $startRow . ':D' . $startRow); // gabung beberapa kolom
          $sheet->getStyle('A' . $startRow)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'fill' => [
              'fillType' => Fill::FILL_SOLID,
              'startColor' => ['rgb' => 'D9E2F3'],
            ],
          ]);

          // Tulis subtotal berdasarkan tipe
          $symbol = 'Rp'; // bisa dari summary jika ada currency
          $col = 'E'; // kolom jumlah biasanya
          switch ($this->type) {
          case 'transactions':
            $sheet->setCellValue($col . $startRow, 'Pemasukan: ' . $symbol . ' ' . number_format($this->summary['total_income'], 0, ',', '.'));
            $startRow++;
            $sheet->setCellValue($col . $startRow, 'Pengeluaran: ' . $symbol . ' ' . number_format($this->summary['total_expense'], 0, ',', '.'));
            $startRow++;
            $sheet->setCellValue($col . $startRow, 'Net: ' . $symbol . ' ' . number_format($this->summary['net'], 0, ',', '.'));
            break;

          case 'transfers':
            $sheet->setCellValue($col . $startRow, 'Total Transfer: ' . $symbol . ' ' . number_format($this->summary['total'], 0, ',', '.'));
            break;

          case 'budgets':
            $sheet->setCellValue($col . $startRow, 'Total Limit: ' . $symbol . ' ' . number_format($this->summary['total_limit'], 0, ',', '.'));
            $startRow++;
            $sheet->setCellValue($col . $startRow, 'Total Pengeluaran: ' . $symbol . ' ' . number_format($this->summary['total_spent'], 0, ',', '.'));
            $startRow++;
            $sheet->setCellValue($col . $startRow, 'Sisa: ' . $symbol . ' ' . number_format($this->summary['remaining'], 0, ',', '.'));
            break;
          }

          // Style subtotal
          $sheet->getStyle('A' . ($dataCount + 1) . ':' . $highestColumn . $startRow)->applyFromArray([
            'borders' => [
              'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'],
              ],
            ],
          ]);

          // --- Footer (sekarang di bawah subtotal) ---
          $footerRow = $startRow + 2;
          $sheet->setCellValue('A' . $footerRow, 'Generated by FinTech App - ' . now()->format('d M Y H:i'));
          $sheet->mergeCells('A' . $footerRow . ':E' . $footerRow);
          $sheet->getStyle('A' . $footerRow)->applyFromArray([
            'font' => [
              'italic' => true,
              'color' => ['rgb' => '888888'],
              'size' => 10,
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT],
          ]);
        },
      ];
    }
  }