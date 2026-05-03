<?php

namespace Modules\FinTech\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AllExcelDataExport implements WithMultipleSheets
{
  private array $sheetsData;

  /**
  * @param array $sheetsData
  *   Dua format yang didukung:
  *   1. ['transactions' => [$data, $summary], 'transfers' => [...], 'budgets' => [...]]
  *      (untuk export tipe "all")
  *   2. ['Nama Sheet' => ['type'=>'transactions','data'=>[...],'summary'=>[...]], ...]
  *      (untuk export multi‑tahun)
  */
  public function __construct(array $sheetsData) {
    $this->sheetsData = $sheetsData;
  }

  public function sheets(): array
  {
    $sheets = [];

    foreach ($this->sheetsData as $sheetTitle => $info) {
      // Format multi‑tahun / custom (disertakan type, data, summary)
      if (isset($info['type'], $info['data'], $info['summary'])) {
        $sheets[] = new ExcelDataExport(
          $info['type'],
          $info['data'],
          $info['summary'],
          $sheetTitle
        );
        continue;
      }

      // Format "all" – key adalah tipe (transactions/transfers/budgets)
      if (is_array($info) && count($info) === 2) {
        [$data,
          $summary] = $info;
        $sheets[] = new ExcelDataExport(
          $sheetTitle, // 'transactions', 'transfers', 'budgets'
          $data,
          $summary,
          $sheetTitle // judul sheet = tipe data
        );
      }
    }

    return $sheets;
  }
}