<?php

namespace Modules\FinTech\Enums;

enum CategoryType: string
{
  case INCOME = 'income';
  case EXPENSE = 'expense';
  case BOTH = 'both';

    /**
    * Mendapatkan label untuk tampilan.
    */
    public function label(): string
    {
      return match($this) {
        self::INCOME => 'Pemasukan',
        self::EXPENSE => 'Pengeluaran',
        self::BOTH => 'Pemasukan & Pengeluaran',
      };
    }

    /**
    * Mendapatkan array nilai untuk validasi.
    */
    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }

    /**
    * Cek apakah tipe ini bisa digunakan untuk transaksi pemasukan.
    */
    public function isForIncome(): bool
    {
      return in_array($this, [self::INCOME, self::BOTH]);
    }

    /**
    * Cek apakah tipe ini bisa digunakan untuk transaksi pengeluaran.
    */
    public function isForExpense(): bool
    {
      return in_array($this, [self::EXPENSE, self::BOTH]);
    }
}