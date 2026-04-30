<?php

namespace Modules\FinTech\Services\Google;

class SheetCursor
{
  public int $row = 1;
  public int $col = 0;

  public function advanceRow(int $count = 1): void {
    $this->row += $count;
    $this->col = 0;
  }

  public function setCol(int $col): void {
    $this->col = $col;
  }

  public function getColLetter(): string {
    return chr(65 + $this->col);
  }
}