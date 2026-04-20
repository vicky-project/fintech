<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_user_settings', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('telegram_users')->cascadeOnDelete();
      $table->string('default_currency', 3)->default('IDR');
        $table->foreignId('default_wallet_id')->nullable()->constrained('fintech_wallets')->nullOnDelete();
        $table->json('preferences')->nullable(); // untuk pengaturan tambahan di masa depan
        $table->timestamps();
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('fintech_user_settings');
    }
  };