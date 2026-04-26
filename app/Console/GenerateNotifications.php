<?php

namespace Modules\FinTech\Console;

use Illuminate\Console\Command;
use Modules\FinTech\Services\NotificationService;

class GenerateNotifications extends Command
{
  protected $signature = 'app:notifications';
  protected $description = 'Generate in-app notifications for all users';

  protected NotificationService $notificationService;

  public function __construct(NotificationService $notificationService) {
    parent::__construct();
    $this->notificationService = $notificationService;
  }

  public function handle(): void
  {
    $this->info('Generating notifications...');
    $this->notificationService->generateForAllUsers();
    $this->info('Done.');
  }
}