<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::create('fintech_notifications', function (Blueprint $table) {
      $table->id();
      $table->foreignId('user_id')->constrained('telegram_users')->cascadeOnDelete();
      $table->string('type'); // 'budget_warning', 'cashflow_warning', 'subscription_reminder'
      $table->string('title');
      $table->text('message');
      $table->json('data')->nullable(); // data tambahan (category_id, wallet_id, dll)
      $table->boolean('is_read')->default(false);
        $table->timestamp('read_at')->nullable();
        $table->timestamps();

        $table->index(['user_id', 'is_read']);
        $table->index('created_at');
      });
    }

    public function down(): void
    {
      Schema::dropIfExists('fintech_notifications');
    }
  };