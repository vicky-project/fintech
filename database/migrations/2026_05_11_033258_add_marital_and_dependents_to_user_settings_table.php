<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  public function up() {
    Schema::table('fintech_user_settings', function (Blueprint $table) {
      if (!Schema::hasColumn('fintech_user_settings', 'marital_status')) {
        $table->string('marital_status')
        ->default('single')
          ->after('google_spreadsheet_id');
        }
        if (!Schema::hasColumn('fintech_user_settings', 'dependents')) {
          $table->tinyInteger('dependents')
          ->default(0)
          ->after('marital_status');
        }
      });
    }

    public function down() {
      Schema::table('fintech_user_settings',
        function (Blueprint $table) {
          $table->dropColumn(['marital_status', 'dependents']);
        });
    }
  };