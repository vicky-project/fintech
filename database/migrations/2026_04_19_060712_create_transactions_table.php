<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_transactions', function (Blueprint $table) {
      $table->id();
      $table->foreignId('wallet_id')->constrained('fintech_wallets')->cascadeOnDelete();
      $table->foreignId('category_id')->constrained('fintech_categories')->restrictOnDelete();
      $table->string('type');
      $table->bigInteger('amount');
      $table->string('description')->nullable();
      $table->date('transaction_date');
      $table->json('metadata')->nullable(); // Untuk data tambahan (misal: transfer ke wallet_id lain)
      $table->timestamps();

      $table->index(['wallet_id', 'transaction_date']);
      $table->index(['category_id', 'transaction_date']);
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('fintech_transactions');
  }
};