<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
  * Run the migrations.
  */
  public function up(): void
  {
    Schema::create('fintech_transfers', function (Blueprint $table) {
      $table->id();
      $table->foreignId('from_wallet_id')->constrained('fintech_wallets')->cascadeOnDelete();
      $table->foreignId('to_wallet_id')->constrained('fintech_wallets')->cascadeOnDelete();
      $table->bigInteger('amount');
      $table->date('transfer_date');
      $table->string('description')->nullable();
      $table->timestamps();
      $table->softDeletes();
    });
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::dropIfExists('fintech_transfers');
  }
};