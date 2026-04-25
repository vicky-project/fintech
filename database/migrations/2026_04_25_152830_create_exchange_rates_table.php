<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_exchange_rates', function (Blueprint $table) {
      $table->id();
      $table->string('from_currency', 3);
      $table->string('to_currency', 3);
      $table->decimal('rate', 15, 6);
      $table->timestamp('fetched_at');
      $table->timestamps();

      $table->unique(['from_currency', 'to_currency'], 'unique_currency_pair');
      $table->index('fetched_at');
    });
  }

  public function down(): void
  {
    Schema::dropIfExists('fintech_exchange_rates');
  }
};