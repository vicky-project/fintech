<?php

namespace Modules\FinTech\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

class CsvDataExport implements FromArray, WithHeadings
{
  private array $data;

  public function __construct(array $data) {
    $this->data = $data;
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
}