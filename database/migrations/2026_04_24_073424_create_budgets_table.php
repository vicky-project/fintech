<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_budgets', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('telegram_users')->cascadeOnDelete();
      $table->foreignId('category_id')->constrained('fintech_categories')->cascadeOnDelete();
      $table->foreignId('wallet_id')->nullable()->constrained('fintech_wallets')->nullOnDelete();
      $table->bigInteger('amount'); // Disimpan dalam satuan terkecil (sen)
      $table->string('period_type')->default('monthly');
        $table->boolean('is_active')->default(true);
        $table->timestamps();

        $table->unique(['user_id', 'category_id', 'period_type', 'wallet_id'], 'unique_user_budget');
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('fintech_budgets');
    }
  };