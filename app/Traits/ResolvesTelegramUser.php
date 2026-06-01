<?php

namespace Modules\FinTech\Traits;

use Modules\Telegram\Models\TelegramUser;

trait ResolvesTelegramUser
{
  protected function getTelegramUser($telegramId): TelegramUser
  {
    return TelegramUser::where('telegram_id', $telegramId)->firstOrFail();
  }
}