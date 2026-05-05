<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_user_category_rules', function (Blueprint $table) {
      $table->id();
      $table->uuid('uuid')->unique();
      $table->foreignId('user_id')->constrained('telegram_users')->cascadeOnDelete();
      $table->foreignId('category_id')->constrained('fintech_categories')->cascadeOnDelete();
      $table->string('keyword');
      $table->float('weight')->default(10);
        $table->unsignedInteger('occurrences')->default(1);
        $table->timestamp('last_used_at')->nullable();
        $table->timestamps();

        $table->unique(['user_id', 'keyword'], 'user_keyword_unique');
        $table->index('category_id');
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('fintech_user_category_rules');
    }
  };