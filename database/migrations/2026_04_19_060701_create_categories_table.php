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
    Schema::create('fintech_categories', function (Blueprint $table) {
      $table->id();
      $table->string('name');
      $table->string('icon')->nullable();
      $table->string('color', 7)->nullable(); // Format #RRGGBB
      $table->string('type')->default('expense');

        // Self-referencing untuk hierarki kategori
        $table->foreignId('parent_id')
        ->nullable()
        ->constrained('fintech_categories')
        ->nullOnDelete();

        // Kategori sistem tidak dapat dihapus oleh user/admin?
        $table->boolean('is_system')->default(false);

        // Status aktif
        $table->boolean('is_active')->default(true);

        // Metadata tambahan (JSON) untuk tags atau keperluan analitik
        $table->json('metadata')->nullable();
        $table->json('keywords')->nullable();

        $table->timestamps();

        // Index untuk performa query
        $table->index(['type', 'is_active']);
        $table->index('parent_id');
      });
    }

    /**
    * Reverse the migrations.
    */
    public function down(): void
    {
      Schema::dropIfExists('fintech_categories');
    }
  };