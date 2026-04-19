<?php

namespace Modules\FinTech\Enums;

enum TransactionType: string
{
  case INCOME = 'income';
  case EXPENSE = 'expense';
  case TRANSFER = 'transfer';

    /**
    * Get label for display
    */
    public function label(): string
    {
      return match($this) {
        self::INCOME => 'Pemasukan',
        self::EXPENSE => 'Pengeluaran',
        self::TRANSFER => 'Transfer',
      };
    }

    /**
    * Get Bootstrap icon class
    */
    public function icon(): string
    {
      return match($this) {
        self::INCOME => 'bi-arrow-down-circle-fill text-success',
        self::EXPENSE => 'bi-arrow-up-circle-fill text-danger',
        self::TRANSFER => 'bi-arrow-left-right text-primary',
      };
    }

    /**
    * Get sign (+ or -) for amount display
    */
    public function sign(): string
    {
      return match($this) {
        self::INCOME => '+',
        self::EXPENSE => '-',
        self::TRANSFER => '↔',
      };
    }

    /**
    * Get all values as array for validation
    */
    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
}