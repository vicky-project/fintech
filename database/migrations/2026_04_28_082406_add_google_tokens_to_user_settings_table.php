<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up(): void
  {
    Schema::table('fintech_user_settings', function (Blueprint $table) {
      $table->text('google_access_token')->nullable()->after('pin');
      $table->text('google_refresh_token')->nullable()->after('google_access_token');
      $table->timestamp('google_token_expires_at')->nullable()->after('google_refresh_token');
      $table->string('google_spreadsheet_id')->nullable()->after('google_token_expires_at');
    });
  }

  public function down(): void
  {
    Schema::table('fintech_user_settings', function (Blueprint $table) {
      $table->dropColumn([
        'google_access_token',
        'google_refresh_token',
        'google_token_expires_at',
        'google_spreadsheet_id',
      ]);
    });
  }
};