<?php

namespace Modules\FinTech\Enums;

enum PeriodType: string
{
  case MONTHLY = 'monthly';
  case YEARLY = 'yearly';

    public function label(): string
    {
      return match($this) {
        self::MONTHLY => 'Bulanan',
        self::YEARLY => 'Tahunan',
      };
    }

    public static function values(): array
    {
      return array_column(self::cases(), 'value');
    }
}