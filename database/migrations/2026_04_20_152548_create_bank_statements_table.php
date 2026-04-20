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
    Schema::create('fintech_bank_statements', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('telegram_users')->cascadeOnDelete();
      $table->foreignId('wallet_id')->nullable()->constrained('fintech_wallets')->nullOnDelete();
      $table->string('original_filename');
      $table->string('file_path');
      $table->string('bank_code')->nullable(); // 'bca', 'mandiri', dll
      $table->string('status')->default('uploaded');
        $table->json('meta_data')->nullable(); // metadata hasil parsing
        $table->timestamp('processed_at')->nullable();
        $table->timestamps();
      });
    }

    /**
    * Reverse the migrations.
    */
    public function down(): void
    {
      Schema::dropIfExists('fintech_bank_statements');
    }
  };