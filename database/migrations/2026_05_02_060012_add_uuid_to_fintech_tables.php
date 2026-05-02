<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
  // Daftar tabel FinTech yang membutuhkan uuid
  protected $tables = [
    'fintech_categories',
    'fintech_wallets',
    'fintech_user_settings',
    'fintech_transactions',
    'fintech_budgets',
    'fintech_transfers',
    'fintech_bank_statements',
    'fintech_statement_transactions',
    'fintech_notifications',
  ];

  public function up() {

    foreach ($this->tables as $tabel) {
      // 1. Tambahkan kolom uuid (nullable + unique index dulu)
      Schema::table($tabel, function (Blueprint $table) {
        $table->uuid('uuid')->nullable()->unique()->after('id');
      });

      // 2. Isi data yang sudah ada
      $this->fillUuidForTable($tabel);

      // 3. Ubah menjadi NOT NULL
      Schema::table($tabel, function (Blueprint $table) {
        $table->uuid('uuid')->nullable(false)->change();
      });
    }
  }

  public function down() {
    foreach ($this->tables as $tabel) {
      Schema::table($tabel, function (Blueprint $table) {
        $table->dropColumn('uuid');
      });
    }
  }

  /**
  * Mengisi uuid untuk seluruh baris yang masih NULL di tabel.
  * Mendukung data besar dengan chunk.
  */
  private function fillUuidForTable(string $table): void
  {
    // Jika jumlah data sangat besar, gunakan raw SQL agar super cepat
    $count = DB::table($table)->whereNull('uuid')->count();

    if ($count === 0) {
      return; // sudah terisi semua, tidak ada yang perlu dilakukan
    }

    // Opsi 1: Untuk data besar, gunakan satu query raw (lebih cepat)
    if ($count > 50000) {
      DB::statement("UPDATE `{$table}` SET `uuid` = UUID() WHERE `uuid` IS NULL");
    } else {
      // Opsi 2: Untuk data menengah, gunakan chunk agar tidak lock lama
      DB::table($table)->whereNull('uuid')->orderBy('id')->chunk(1000, function ($records) use ($table) {
        $updates = [];
        foreach ($records as $record) {
          // generate UUID di sisi aplikasi (ordered UUID untuk index performance)
          $updates[] = [
            'id' => $record->id,
            'uuid' => Str::orderedUuid()->toString(),
          ];
        }
        // Update batch dengan CASE... tapi lebih mudah per record
        foreach ($updates as $update) {
          DB::table($table)->where('id', $update['id'])->update(['uuid' => $update['uuid']]);
        }
      });
    }
  }
};