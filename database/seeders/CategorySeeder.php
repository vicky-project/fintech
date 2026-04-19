<?php

namespace Modules\FinTech\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\FinTech\Enums\CategoryType;
use Modules\FinTech\Models\Category;

class CategorySeeder extends Seeder
{
  public function run(): void
  {
    // ========== KATEGORI PENGELUARAN (EXPENSE) ==========

    // 1. Makanan & Minuman
    $foodParent = Category::updateOrCreate(
      ['name' => 'Makanan & Minuman'],
      [
        'icon' => 'bi-cup-hot',
        'color' => '#FF6384',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok', 'harian']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Makanan'],
      [
        'icon' => 'bi-egg-fried',
        'color' => '#FF6384',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $foodParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Minuman'],
      [
        'icon' => 'bi-cup',
        'color' => '#FF9F40',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $foodParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Makan di Luar'],
      [
        'icon' => 'bi-shop',
        'color' => '#FFCE56',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $foodParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['hiburan', 'sekunder']]
      ]
    );

    // 2. Transportasi
    $transportParent = Category::updateOrCreate(
      ['name' => 'Transportasi'],
      [
        'icon' => 'bi-car-front',
        'color' => '#36A2EB',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok', 'mobilitas']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Bahan Bakar'],
      [
        'icon' => 'bi-fuel-pump',
        'color' => '#4BC0C0',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $transportParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['rutin']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Transportasi Umum'],
      [
        'icon' => 'bi-bus-front',
        'color' => '#9966FF',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $transportParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['rutin']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Ojek Online / Taksi'],
      [
        'icon' => 'bi-bicycle',
        'color' => '#FF9F40',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $transportParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['sekunder', 'mobilitas']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Parkir & Tol'],
      [
        'icon' => 'bi-p-circle',
        'color' => '#8AC24A',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $transportParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['insidental']]
      ]
    );

    // 3. Belanja
    $shoppingParent = Category::updateOrCreate(
      ['name' => 'Belanja'],
      [
        'icon' => 'bi-cart',
        'color' => '#FFCE56',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['sekunder']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Kebutuhan Rumah Tangga'],
      [
        'icon' => 'bi-house',
        'color' => '#4DB6AC',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $shoppingParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Pakaian'],
      [
        'icon' => 'bi-bag',
        'color' => '#F06292',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $shoppingParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['sekunder', 'tersier']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Elektronik & Gadget'],
      [
        'icon' => 'bi-laptop',
        'color' => '#7986CB',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $shoppingParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['tersier', 'investasi']]
      ]
    );

    // 4. Tagihan & Utilitas
    $billsParent = Category::updateOrCreate(
      ['name' => 'Tagihan & Utilitas'],
      [
        'icon' => 'bi-receipt',
        'color' => '#4BC0C0',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok', 'rutin']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Listrik'],
      [
        'icon' => 'bi-lightning',
        'color' => '#FFCE56',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $billsParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['wajib']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Air'],
      [
        'icon' => 'bi-droplet',
        'color' => '#36A2EB',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $billsParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['wajib']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Internet & Pulsa'],
      [
        'icon' => 'bi-wifi',
        'color' => '#9966FF',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $billsParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok', 'digital']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Sewa / KPR'],
      [
        'icon' => 'bi-building',
        'color' => '#FF6384',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $billsParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['wajib', 'besar']]
      ]
    );

    // 5. Hiburan
    $entertainmentParent = Category::updateOrCreate(
      ['name' => 'Hiburan'],
      [
        'icon' => 'bi-film',
        'color' => '#9966FF',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['tersier', 'non-esensial']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Streaming & Langganan'],
      [
        'icon' => 'bi-play-circle',
        'color' => '#FF9F40',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $entertainmentParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['digital', 'bulanan']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Bioskop & Acara'],
      [
        'icon' => 'bi-ticket-perforated',
        'color' => '#F06292',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $entertainmentParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['insidental']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Game'],
      [
        'icon' => 'bi-controller',
        'color' => '#7986CB',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $entertainmentParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['digital']]
      ]
    );

    // 6. Kesehatan
    $healthParent = Category::updateOrCreate(
      ['name' => 'Kesehatan'],
      [
        'icon' => 'bi-heart-pulse',
        'color' => '#FF9F40',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok', 'darurat']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Obat & Vitamin'],
      [
        'icon' => 'bi-capsule',
        'color' => '#8AC24A',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $healthParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['rutin']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Dokter & Rumah Sakit'],
      [
        'icon' => 'bi-hospital',
        'color' => '#FF6384',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $healthParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['insidental', 'darurat']]
      ]
    );

    // 7. Pendidikan
    $educationParent = Category::updateOrCreate(
      ['name' => 'Pendidikan'],
      [
        'icon' => 'bi-book',
        'color' => '#8AC24A',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['investasi', 'sekunder']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Kursus & Pelatihan'],
      [
        'icon' => 'bi-mortarboard',
        'color' => '#4DB6AC',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $educationParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['investasi_diri']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Buku & Materi'],
      [
        'icon' => 'bi-journal',
        'color' => '#36A2EB',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $educationParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['pengetahuan']]
      ]
    );

    // 8. Lainnya (Expense)
    Category::updateOrCreate(
      ['name' => 'Lainnya'],
      [
        'icon' => 'bi-three-dots',
        'color' => '#7986CB',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['tidak_terkategori']]
      ]
    );

    // ========== KATEGORI PEMASUKAN (INCOME) ==========

    $incomeParent = Category::updateOrCreate(
      ['name' => 'Pendapatan Utama'],
      [
        'icon' => 'bi-cash-stack',
        'color' => '#4DB6AC',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['gaji', 'rutin', 'primer']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Gaji'],
      [
        'icon' => 'bi-briefcase',
        'color' => '#36A2EB',
        'type' => CategoryType::INCOME,
        'parent_id' => $incomeParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['bulanan', 'tetap']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Bonus'],
      [
        'icon' => 'bi-gift',
        'color' => '#FFCE56',
        'type' => CategoryType::INCOME,
        'parent_id' => $incomeParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['tidak_tetap']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Investasi'],
      [
        'icon' => 'bi-graph-up',
        'color' => '#F06292',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['pasif', 'modal']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Dividen'],
      [
        'icon' => 'bi-pie-chart',
        'color' => '#7986CB',
        'type' => CategoryType::INCOME,
        'parent_id' => Category::where('name', 'Investasi')->first()->id,
        'is_system' => true,
        'metadata' => ['tags' => ['saham']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Bunga Deposito'],
      [
        'icon' => 'bi-bank',
        'color' => '#8AC24A',
        'type' => CategoryType::INCOME,
        'parent_id' => Category::where('name', 'Investasi')->first()->id,
        'is_system' => true,
        'metadata' => ['tags' => ['perbankan']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Pendapatan Sampingan'],
      [
        'icon' => 'bi-brush',
        'color' => '#FF9F40',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['freelance', 'tidak_tetap']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Hadiah / Uang Saku'],
      [
        'icon' => 'bi-gift-fill',
        'color' => '#FF6384',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['insidental']]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Lainnya (Pemasukan)'],
      [
        'icon' => 'bi-three-dots',
        'color' => '#6c757d',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['tidak_terkategori']]
      ]
    );
  }
}