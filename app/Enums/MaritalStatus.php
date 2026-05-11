<?php

namespace Modules\FinTech\Enums;

enum MaritalStatus: string
{
  case SINGLE = 'single';
  case MARRIED = 'married';
  case DIVORCED = 'divorced';
  case WIDOWED = 'widowed';

    /**
    * Get label for display.
    */
    public function label(): string
    {
      return match($this) {
        self::SINGLE => 'Belum Kawin (TK/0)',
        self::MARRIED => 'Kawin Tanpa Tanggungan (K/0)',
        self::DIVORCED => 'Cerai',
        self::WIDOWED => 'Janda/Duda',
      };
    }

    /**
    * Get base PTKP amount for this status (without dependents).
    */
    public function basePTKP(): int
    {
      return match($this) {
        self::SINGLE,
        self::DIVORCED,
        self::WIDOWED => 54000000,
        self::MARRIED => 58500000,
        // K/0
      };
    }

    /**
    * Get all values for form select.
    */
    public static function options(): array
    {
      return collect(self::cases())->mapWithKeys(fn($case) => [
        $case->value => $case->label()
      ])->toArray();
    }
}