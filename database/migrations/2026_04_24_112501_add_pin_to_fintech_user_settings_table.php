<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table("fintech_user_settings", function(Blueprint $table) {
      $table->string('pin', 60)->nullable()->after('default_wallet_id');
      $table->boolean('pin_enabled')->default(false)->after('pin');
      });
    }

    public function down(): void
    {
      Schema::table("fintech_user_settings", function(Blueprint $table) {
        $table->dropColumn(['pin', 'pin_enabled']);
      });
    }
  };