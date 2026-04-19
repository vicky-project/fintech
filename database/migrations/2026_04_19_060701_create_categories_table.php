<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_categories', function (Blueprint $table) {
      $table->id();
      $table->string('name')->unique(); // Contoh: "Makanan", "Transportasi"
      $table->string('icon')->nullable(); // Bootstrap icon class
      $table->string('color')->nullable(); // Hex color untuk chart
      $table->boolean('is_active')->default(true);
        $table->timestamps();
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('fintech_categories');
    }
  };