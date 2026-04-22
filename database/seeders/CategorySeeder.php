<?php

namespace Modules\FinTech\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Enums\CategoryType;

class CategorySeeder extends Seeder
{
  public function run(): void
  {
    // ==================== KATEGORI PENGELUARAN (EXPENSE) ====================

    // 1. Makanan & Minuman
    $foodParent = Category::updateOrCreate(
      ['name' => 'Makanan & Minuman'],
      [
        'icon' => 'bi-cup-hot',
        'color' => '#FF6384',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['kebutuhan_pokok', 'harian']],
        'keywords' => [
          'restoran', 'rm ', 'warung', 'kafe', 'cafe', 'coffee', 'mcd', 'kfc', 'pizza', 'burger',
          'sushi', 'steak', 'martabak', 'bakso', 'sate', 'nasi goreng', 'mie ayam', 'gado-gado',
          'sarapan', 'makan siang', 'makan malam', 'minum', 'kopi', 'teh', 'jus', 'es',
          'gofood', 'grabfood', 'shopeefood', 'food', 'kuliner', 'catering', 'kantin',
          'snack', 'cemilan', 'roti', 'kue', 'cokelat', 'es krim', 'ice cream', 'boba',
          'starbucks', 'hokben', 'solaria', 'wendy', 'a&w', 'dunkin', 'jco'
        ]
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
        'metadata' => ['tags' => ['kebutuhan_pokok']],
        'keywords' => [
          'makan', 'nasi', 'lauk', 'sayur', 'sarapan', 'makan siang', 'makan malam',
          'resto', 'restaurant', 'rm', 'warung makan', 'warteg', 'prasmanan'
        ]
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
        'metadata' => ['tags' => ['kebutuhan_pokok']],
        'keywords' => [
          'minum', 'kopi', 'teh', 'jus', 'es', 'boba', 'bubble tea', 'soda', 'air mineral',
          'starbucks', 'chatime', 'kopi kenangan', 'janji jiwa', 'fore coffee'
        ]
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
        'metadata' => ['tags' => ['hiburan', 'sekunder']],
        'keywords' => [
          'restoran', 'cafe', 'kafe', 'dine in', 'dine out', 'makan malam romantis',
          'steak', 'sushi', 'all you can eat', 'buffet', 'fine dining'
        ]
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
        'metadata' => ['tags' => ['kebutuhan_pokok', 'mobilitas']],
        'keywords' => [
          'transport', 'transportasi', 'bensin', 'pertamina', 'shell', 'bp', 'vivo',
          'spbu', 'bbm', 'bahan bakar', 'parkir', 'tol', 'e-toll', 'flazz', 'brizzi',
          'gojek', 'grab', 'maxim', 'ojek', 'taksi', 'blue bird', 'bus', 'kereta',
          'commuter line', 'krl', 'mrt', 'lrt', 'transjakarta', 'tj', 'damri',
          'travel', 'tiket pesawat', 'garuda', 'lion air', 'citilink', 'air asia'
        ]
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
        'metadata' => ['tags' => ['rutin']],
        'keywords' => [
          'bensin', 'pertamax', 'pertalite', 'dex', 'solar', 'spbu', 'bbm', 'fuel',
          'shell', 'bp', 'vivo', 'pengisian', 'isi bensin'
        ]
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
        'metadata' => ['tags' => ['rutin']],
        'keywords' => [
          'bus', 'kereta', 'krl', 'commuter', 'mrt', 'lrt', 'transjakarta', 'tj',
          'damri', 'angkot', 'angkutan', 'tiket kereta', 'tiket bus'
        ]
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
        'metadata' => ['tags' => ['sekunder', 'mobilitas']],
        'keywords' => [
          'gojek', 'grab', 'maxim', 'ojol', 'ojek', 'goride', 'gocar', 'grabbike',
          'taksi', 'blue bird', 'express', 'silver bird', 'taksi online'
        ]
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
        'metadata' => ['tags' => ['insidental']],
        'keywords' => [
          'parkir', 'parking', 'tol', 'e-toll', 'flazz', 'brizzi', 'jalan tol',
          'jasa marga', 'rest area'
        ]
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
        'metadata' => ['tags' => ['sekunder']],
        'keywords' =>
        [
          'belanja', 'shopping', 'mall', 'plaza', 'supermarket', 'minimarket',
          'indomaret', 'alfamart', 'alfamidi', 'circle k', 'lawson', 'family mart',
          'transmart', 'carrefour', 'hypermart', 'giant', 'lotte mart', 'aeon',
          'toko', 'shopee', 'tokopedia', 'lazada', 'blibli', 'bukalapak', 'amazon',
          'e-commerce', 'online shop'
        ]
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
        'metadata' => ['tags' => ['kebutuhan_pokok']],
        'keywords' => [
          'sembako', 'beras', 'minyak goreng', 'gula', 'garam', 'tepung', 'telur',
          'susu', 'roti', 'sabun', 'deterjen', 'pembersih', 'tisu', 'popok',
          'kebutuhan rumah', 'perlengkapan rumah', 'alat dapur', 'perabot'
        ]
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
        'metadata' => ['tags' => ['sekunder', 'tersier']],
        'keywords' => [
          'baju', 'celana', 'sepatu', 'sandal', 'tas', 'dompet', 'aksesoris',
          'fashion', 'pakaian', 'kaos', 'kemeja', 'dress', 'rok', 'jaket',
          'h&m', 'uniqlo', 'zara', 'mango', 'pull&bear', 'stradivarius', 'bershka'
        ]
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
        'metadata' => ['tags' => ['tersier', 'investasi']],
        'keywords' => [
          'hp', 'handphone', 'smartphone', 'laptop', 'komputer', 'tablet', 'ipad',
          'iphone', 'samsung', 'xiaomi', 'oppo', 'vivo', 'realme', 'macbook',
          'elektronik', 'gadget', 'aksesoris hp', 'charger', 'headset', 'earphone'
        ]
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
        'metadata' => ['tags' => ['kebutuhan_pokok', 'rutin']],
        'keywords' =>
        [
          'tagihan', 'bill', 'pln', 'listrik', 'pdam', 'air', 'pgn', 'gas',
          'internet', 'wifi', 'indihome', 'first media', 'biznet', 'myrepublic',
          'pulsa', 'telkomsel', 'xl', 'indosat', 'tri', 'smartfren', 'axis',
          'paket data', 'kuota', 'bpjs', 'asuransi', 'premi', 'sewa', 'kontrakan',
          'kost', 'kos', 'apartemen', 'rumah', 'cicilan', 'kpr', 'leasing'
        ]
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
        'metadata' => ['tags' => ['wajib']],
        'keywords' => ['pln', 'listrik', 'token listrik', 'pascabayar', 'prabayar']
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
        'metadata' => ['tags' => ['wajib']],
        'keywords' => ['pdam', 'air', 'pam', 'palang', 'palapa']
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
        'metadata' => ['tags' => ['kebutuhan_pokok', 'digital']],
        'keywords' =>
        [
          'internet', 'wifi', 'indihome', 'first media', 'biznet', 'myrepublic',
          'pulsa', 'telkomsel', 'xl', 'indosat', 'tri', 'smartfren', 'axis',
          'paket data', 'kuota', 'isi ulang', 'voucher'
        ]
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
        'metadata' => ['tags' => ['wajib', 'besar']],
        'keywords' =>
        [
          'sewa', 'kontrakan', 'kost', 'kos', 'apartemen', 'rumah', 'kpr',
          'cicilan rumah', 'angsuran', 'pembayaran sewa', 'rent'
        ]
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
        'metadata' => ['tags' => ['tersier', 'non-esensial']],
        'keywords' => [
          'hiburan', 'entertainment', 'bioskop', 'cinema', 'xxi', 'cgv', 'cinepolis',
          'netflix', 'spotify', 'youtube', 'disney', 'hbo', 'vidio', 'viki', 'iqiyi',
          'we tv', 'langganan', 'subscription', 'game', 'steam', 'playstation',
          'nintendo', 'xbox', 'mobile legend', 'pubg', 'free fire', 'genshin',
          'konser', 'event', 'tiket', 'pertunjukan', 'teater', 'museum', 'wisata'
        ]
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
        'metadata' => ['tags' => ['digital',
          'bulanan']],
        'keywords' => [
          'netflix',
          'spotify',
          'youtube premium',
          'disney+',
          'hbo go',
          'vidio',
          'viki',
          'iqiyi',
          'we tv',
          'langganan',
          'subscription'
        ]
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
        'metadata' => ['tags' => ['insidental']],
        'keywords' => [
          'bioskop',
          'cinema',
          'xxi',
          'cgv',
          'cinepolis',
          'tiket',
          'nonton',
          'konser',
          'event',
          'pertunjukan',
          'teater',
          'stand up',
          'comedy'
        ]
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
        'metadata' => ['tags' => ['digital']],
        'keywords' => [
          'game',
          'steam',
          'playstation',
          'psn',
          'nintendo',
          'xbox',
          'game pass',
          'mobile legend',
          'pubg',
          'free fire',
          'genshin impact',
          'valorant',
          'top up',
          'diamond',
          'uc',
          'skin',
          'item game',
          'dlc'
        ]
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
        'metadata' => ['tags' => ['kebutuhan_pokok',
          'darurat']],
        'keywords' => [
          'kesehatan',
          'health',
          'dokter',
          'rumah sakit',
          'klinik',
          'puskesmas',
          'obat',
          'apotek',
          'farmasi',
          'vitamin',
          'suplemen',
          'bpjs kesehatan',
          'asuransi kesehatan',
          'medical',
          'check up',
          'laboratorium',
          'rontgen',
          'gigi',
          'dokter gigi',
          'behel',
          'kacamata',
          'softlens',
          'optik'
        ]
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
        'metadata' => ['tags' => ['rutin']],
        'keywords' => [
          'obat',
          'vitamin',
          'suplemen',
          'apotek',
          'kimia farma',
          'century',
          'guardian',
          'watson',
          'obat resep',
          'obat bebas'
        ]
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
        'metadata' => ['tags' => ['insidental',
          'darurat']],
        'keywords' => [
          'dokter',
          'rumah sakit',
          'rs ',
          'klinik',
          'puskesmas',
          'ugd',
          'igd',
          'rawat inap',
          'operasi',
          'bedah',
          'poli',
          'spesialis'
        ]
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
        'metadata' => ['tags' => ['investasi',
          'sekunder']],
        'keywords' => [
          'pendidikan',
          'education',
          'sekolah',
          'kuliah',
          'universitas',
          'spp',
          'uang pangkal',
          'biaya pendidikan',
          'kursus',
          'les',
          'bimbel',
          'ruang guru',
          'zenius',
          'pijar',
          'buku',
          'alat tulis',
          'stationery',
          'seminar',
          'workshop',
          'pelatihan',
          'training',
          'sertifikasi',
          'bootcamp',
          'online course',
          'udemy',
          'coursera',
          'dicoding',
          'harisenin',
          'revou'
        ]
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
        'metadata' => ['tags' => ['investasi_diri']],
        'keywords' => [
          'kursus',
          'les',
          'bimbel',
          'pelatihan',
          'training',
          'sertifikasi',
          'bootcamp',
          'online course',
          'udemy',
          'coursera',
          'dicoding'
        ]
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
        'metadata' => ['tags' => ['pengetahuan']],
        'keywords' => [
          'buku',
          'novel',
          'komik',
          'majalah',
          'ebook',
          'kindle',
          'gramedia',
          'periplus',
          'alat tulis',
          'stationery',
          'pensil',
          'pulpen',
          'binder'
        ]
      ]
    );

    // 8. Transfer & Keuangan
    $transferParent = Category::updateOrCreate(
      ['name' => 'Transfer & Keuangan'],
      [
        'icon' => 'bi-arrow-left-right',
        'color' => '#6c757d',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['transfer',
          'keuangan']],
        'keywords' => [
          'transfer',
          'trsf',
          'bi fast',
          'kliring',
          'rtgs',
          'skn',
          'antar bank',
          'biaya admin',
          'administrasi',
          'admin bank',
          'biaya transfer',
          'pajak',
          'bunga',
          'denda',
          'penalty',
          'fee'
        ]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Biaya Admin & Pajak'],
      [
        'icon' => 'bi-calculator',
        'color' => '#6c757d',
        'type' => CategoryType::EXPENSE,
        'parent_id' => $transferParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['wajib']],
        'keywords' => json_encode([
          'biaya admin', 'administrasi', 'admin bank', 'biaya transfer',
          'pajak', 'pph', 'ppn', 'bunga', 'denda', 'penalty', 'fee',
          'biaya', 'biaya transaksi', 'biaya pembayaran', 'biaya layanan'
        ])
      ]
    );

    // 9. Lainnya (Expense)
    Category::updateOrCreate(
      ['name' => 'Lainnya'],
      [
        'icon' => 'bi-three-dots',
        'color' => '#7986CB',
        'type' => CategoryType::EXPENSE,
        'is_system' => true,
        'metadata' => ['tags' => ['tidak_terkategori']],
        'keywords' => ['lainnya',
          'other',
          'uncategorized']
      ]
    );

    // ==================== KATEGORI PEMASUKAN (INCOME) ====================

    // 1. Pendapatan Utama
    $incomeParent = Category::updateOrCreate(
      ['name' => 'Pendapatan Utama'],
      [
        'icon' => 'bi-cash-stack',
        'color' => '#4DB6AC',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['gaji',
          'rutin',
          'primer']],
        'keywords' => [
          'gaji',
          'salary',
          'upah',
          'wage',
          'honor',
          'honorarium',
          'tunjangan',
          'bonus',
          'insentif',
          'thr',
          'lembur',
          'overtime',
          'pensiun',
          'pension'
        ]
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
        'metadata' => ['tags' => ['bulanan',
          'tetap']],
        'keywords' => [
          'gaji',
          'salary',
          'payroll',
          'gajian',
          'upah',
          'wage'
        ]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Bonus & THR'],
      [
        'icon' => 'bi-gift',
        'color' => '#FFCE56',
        'type' => CategoryType::INCOME,
        'parent_id' => $incomeParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['tidak_tetap']],
        'keywords' => [
          'bonus',
          'insentif',
          'thr',
          'tunjangan hari raya',
          'reward',
          'komisi'
        ]
      ]
    );

    // 2. Investasi
    $investmentParent = Category::updateOrCreate(
      ['name' => 'Investasi'],
      [
        'icon' => 'bi-graph-up',
        'color' => '#F06292',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['pasif',
          'modal']],
        'keywords' => [
          'investasi',
          'investment',
          'dividen',
          'dividend',
          'saham',
          'stock',
          'reksadana',
          'mutual fund',
          'obligasi',
          'bond',
          'deposito',
          'bunga',
          'kripto',
          'crypto',
          'bitcoin',
          'ethereum',
          'forex',
          'trading',
          'profit'
        ]
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Dividen'],
      [
        'icon' => 'bi-pie-chart',
        'color' => '#7986CB',
        'type' => CategoryType::INCOME,
        'parent_id' => $investmentParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['saham']],
        'keywords' => ['dividen',
          'dividend',
          'pembagian laba']
      ]
    );

    Category::updateOrCreate(
      ['name' => 'Bunga Deposito'],
      [
        'icon' => 'bi-bank',
        'color' => '#8AC24A',
        'type' => CategoryType::INCOME,
        'parent_id' => $investmentParent->id,
        'is_system' => true,
        'metadata' => ['tags' => ['perbankan']],
        'keywords' => ['bunga',
          'deposito',
          'interest',
          'tabungan']
      ]
    );

    // 3. Pendapatan Sampingan
    Category::updateOrCreate(
      ['name' => 'Pendapatan Sampingan'],
      [
        'icon' => 'bi-brush',
        'color' => '#FF9F40',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['freelance',
          'tidak_tetap']],
        'keywords' => [
          'freelance',
          'side hustle',
          'sampingan',
          'proyek',
          'project',
          'jasa',
          'service',
          'konsultan',
          'desain',
          'nulis',
          'fotografi',
          'videografi',
          'editing',
          'ngajar',
          'private',
          'tutor'
        ]
      ]
    );

    // 4. Hadiah & Transfer Masuk
    Category::updateOrCreate(
      ['name' => 'Hadiah & Transfer Masuk'],
      [
        'icon' => 'bi-gift-fill',
        'color' => '#FF6384',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['insidental']],
        'keywords' => [
          'hadiah',
          'gift',
          'transfer masuk',
          'incoming transfer',
          'uang saku',
          'allowance',
          'kiriman',
          'remittance',
          'pemberian',
          'angpao',
          'amplop'
        ]
      ]
    );

    // 5. Lainnya (Income)
    Category::updateOrCreate(
      ['name' => 'Lainnya (Pemasukan)'],
      [
        'icon' => 'bi-three-dots',
        'color' => '#6c757d',
        'type' => CategoryType::INCOME,
        'is_system' => true,
        'metadata' => ['tags' => ['tidak_terkategori']],
        'keywords' => ['lainnya',
          'other',
          'uncategorized income']
      ]
    );

    // ==================== KATEGORI BOTH (Transfer Internal) ====================

    Category::updateOrCreate(
      ['name' => 'Transfer Internal'],
      [
        'icon' => 'bi-arrow-left-right',
        'color' => '#17a2b8',
        'type' => CategoryType::BOTH,
        'is_system' => true,
        'metadata' => ['tags' => ['transfer',
          'internal']],
        'keywords' => ['transfer antar dompet',
          'internal transfer']
      ]
    );
  }
}