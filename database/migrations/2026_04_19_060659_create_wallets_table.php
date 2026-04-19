<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_wallets', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('telegram_users')->cascadeOnDelete();
      $table->string('name'); // Nama dompet (contoh: "BCA", "Cash")
      $table->bigInteger('balance')->default(0);
        $table->string('currency', 10)->default('IDR');
        $table->text('description')->nullable();
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->index(['user_id', 'is_active']);
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('fintech_wallets');
    }
  };