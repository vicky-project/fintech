<?php

namespace Modules\FinTech\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class AllExcelDataExport implements WithMultipleSheets
{
  private array $allData;

  public function __construct(array $allData) {
    $this->allData = $allData;
  }

  public function sheets(): array
  {
    return [
      new ExcelDataExport('transactions', $this->allData['transactions'][0], $this->allData['transactions'][1]),
      new ExcelDataExport('transfers', $this->allData['transfers'][0], $this->allData['transfers'][1]),
      new ExcelDataExport('budgets', $this->allData['budgets'][0], $this->allData['budgets'][1]),
    ];
  }
}