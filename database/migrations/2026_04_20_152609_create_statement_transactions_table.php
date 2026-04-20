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
    Schema::create('fintech_statement_transactions', function (Blueprint $table) {
      $table->id();
      $table->foreignId('statement_id')->constrained('fintech_bank_statements')->cascadeOnDelete();
      $table->date('transaction_date');
      $table->string('description');
      $table->bigInteger('amount');
      $table->string('type')->nullable();
      $table->foreignId('category_id')->nullable()->constrained('fintech_categories')->nullOnDelete();
      $table->boolean('is_imported')->default(false);
        $table->json('raw_data')->nullable();
        $table->timestamps();
      });
    }

    /**
    * Reverse the migrations.
    */
    public function down(): void
    {
      Schema::dropIfExists('fintech_statement_transactions');
    }
  };