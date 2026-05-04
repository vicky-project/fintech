<?php

namespace Modules\FinTech\Composer;

use Composer\Script\Event;

class CheckMsoffcrypto
{
  public static function check(Event $event): void
  {
    $io = $event->getIO();

    // Cek apakah binary tersedia
    exec('msoffcrypto-tool --version 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
      $io->warning(
        'Binary msoffcrypto-tool tidak ditemukan. ' .
        'Fitur dekripsi Excel terproteksi tidak akan berfungsi. ' .
        'Install dengan: pip install msoffcrypto-tool'
      );
    } else {
      $io->info('Binary msoffcrypto-tool: Tersedia ✓');
    }
  }
}