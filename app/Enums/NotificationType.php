<?php

namespace Modules\FinTech\Enums;

enum NotificationType: string
{
  case BUDGET_WARNING = 'budget_warning';
  case CASHFLOW_WARNING = 'cashflow_warning';
  case SUBSCRIPTION_REMINDER = 'subscription_reminder';

    public function label(): string
    {
      return match($this) {
        self::BUDGET_WARNING => 'Peringatan Budget',
        self::CASHFLOW_WARNING => 'Peringatan Arus Kas',
        self::SUBSCRIPTION_REMINDER => 'Pengingat Langganan',
      };
    }
}