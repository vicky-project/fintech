<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'FinTech') — Keuangan Digital</title>

  {{-- Bootstrap 5 CSS --}}
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  {{-- Bootstrap Icons --}}
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  {{-- Chart.js --}}
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <style>
:root {
    --sidebar-width: 260px;
    --primary-gradient: linear-gradient(135deg, #667eea, #764ba2);
    }

    body {
    background-color: #f1f5f9;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    overflow-x: hidden;
    }

    /* ========== SIDEBAR ========== */
    .sidebar {
    position: fixed;
    top: 0;
    bottom: 0;
    left: 0;
    width: var(--sidebar-width);
    background: linear-gradient(180deg, #1e293b 0%, #0f172a 100%);
    color: #cbd5e1;
    z-index: 1040;
    overflow-y: auto;
    transition: transform 0.3s ease;
    box-shadow: 2px 0 12px rgba(0,0,0,0.15);
    }

    .sidebar .brand {
    display: block;
    padding: 1.5rem 1.25rem;
    font-size: 1.35rem;
    font-weight: 700;
    color: #fff;
    text-decoration: none;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 0.5rem;
    }
    .sidebar .brand i {
    color: #667eea;
    }

    .sidebar .nav-link {
    color: #94a3b8;
    border-radius: 0.5rem;
    margin: 0.15rem 0.75rem;
    padding: 0.7rem 1rem;
    transition: all 0.2s;
    font-size: 0.925rem;
    }
    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
    color: #fff;
    background: rgba(102, 126, 234, 0.2);
    }
    .sidebar .nav-link i {
    width: 22px;
    text-align: center;
    margin-right: 0.75rem;
    font-size: 1.1rem;
    }
    .sidebar .nav-section {
    color: #64748b;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    padding: 1rem 1.25rem 0.35rem;
    font-weight: 600;
    }

    /* ========== MAIN CONTENT ========== */
    .main-wrapper {
    margin-left: var(--sidebar-width);
    min-height: 100vh;
    transition: margin-left 0.3s ease;
    }

    /* ========== TOPBAR ========== */
    .topbar {
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    padding: 0.75rem 1.5rem;
    position: sticky;
    top: 0;
    z-index: 1030;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    }
    .topbar .btn-sidebar-toggle {
    display: none;
    }

    /* ========== CONTENT AREA ========== */
    .content-area {
    padding: 1.75rem;
    }

    /* ========== CARDS ========== */
    .card-stat {
    border: none;
    border-radius: 1rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    transition: transform 0.2s, box-shadow 0.2s;
    }
    .card-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    /* ========== TABLE ========== */
    .table-fintech th {
    font-weight: 600;
    font-size: 0.8rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    border-bottom-width: 2px;
    }
    .table-fintech td {
    vertical-align: middle;
    padding: 0.75rem 1rem;
    }

    /* ========== BADGE ========== */
    .badge-soft-success { background: #d1fae5; color: #065f46; }
    .badge-soft-danger  { background: #fee2e2; color: #991b1b; }
    .badge-soft-warning { background: #fef3c7; color: #92400e; }
    .badge-soft-info    { background: #dbeafe; color: #1e40af; }

    /* ========== MOBILE ========== */
    @media (max-width: 991.98px) {
    .sidebar {
    transform: translateX(-100%);
    }
    .sidebar.mobile-show {
    transform: translateX(0);
    }
    .main-wrapper {
    margin-left: 0;
    }
    .topbar .btn-sidebar-toggle {
    display: inline-block;
    }
    .sidebar-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1035;
    }
    .sidebar-backdrop.show {
    display: block;
    }
    }

    /* ========== MISC ========== */
    .text-truncate-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    }
    .cursor-pointer {
    cursor: pointer;
    }
    </style>
    @stack('styles')
    </head>
    <body>

    {{-- Mobile Backdrop --}}
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

    {{-- SIDEBAR --}}
    <aside class="sidebar" id="sidebar">
    <a href="{{ route('fintech.home') }}" class="brand">
    <i class="bi bi-cash-stack me-2"></i>FinTech
    </a>

    <nav class="nav flex-column">
    <div class="nav-section">Menu Utama</div>

    @php
    $menuItems = [
    ['route' => 'fintech.home',           'icon' => 'bi-speedometer2',    'label' => 'Dashboard'],
    ['route' => 'fintech.transactions.index', 'icon' => 'bi-list-ul',    'label' => 'Transaksi'],
    ['route' => 'fintech.wallets.index',     'icon' => 'bi-wallet2',      'label' => 'Dompet'],
    ['route' => 'fintech.transfers.index',   'icon' => 'bi-arrow-left-right','label' => 'Transfer'],
    ['route' => 'fintech.budgets.index',     'icon' => 'bi-pie-chart',     'label' => 'Budget'],
    ];
    $menuTools = [
    ['route' => 'fintech.reports.index',     'icon' => 'bi-graph-up',       'label' => 'Laporan'],
    ['route' => 'fintech.statements.index',  'icon' => 'bi-file-earmark-text','label' => 'Statement'],
    ['route' => 'fintech.insights.index',    'icon' => 'bi-lightbulb',      'label' => 'Insight'],
    ['route' => 'fintech.zakat.index',       'icon' => 'bi-calculator',     'label' => 'Zakat & Pajak'],
    ['route' => 'fintech.export.index',      'icon' => 'bi-cloud-download', 'label' => 'Export'],
    ];
    $menuOther = [
    ['route' => 'fintech.notifications.index','icon' => 'bi-bell',          'label' => 'Notifikasi'],
    ['route' => 'fintech.search',            'icon' => 'bi-search',         'label' => 'Pencarian'],
    ['route' => 'fintech.settings',          'icon' => 'bi-sliders',        'label' => 'Pengaturan'],
    ];
    @endphp

    @foreach($menuItems as $item)
    <a href="{{ route($item['route']) }}"
    class="nav-link {{ request()->routeIs($item['route'].'*') ? 'active' : '' }}">
    <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
    </a>
    @endforeach

    <div class="nav-section">Analisis</div>
    @foreach($menuTools as $item)
    <a href="{{ route($item['route']) }}"
    class="nav-link {{ request()->routeIs($item['route'].'*') ? 'active' : '' }}">
    <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
    </a>
    @endforeach

    <div class="nav-section">Lainnya</div>
    @foreach($menuOther as $item)
    <a href="{{ route($item['route']) }}"
    class="nav-link {{ request()->routeIs($item['route'].'*') ? 'active' : '' }}">
    <i class="bi {{ $item['icon'] }}"></i> {{ $item['label'] }}
    </a>
    @endforeach
    </nav>

    <div class="mt-auto px-3 pb-3 pt-4">
    <form action="{{ route('logout') }}" method="POST">
    @csrf
    <button type="submit" class="btn btn-outline-light btn-sm w-100">
    <i class="bi bi-box-arrow-right me-2"></i>Keluar
    </button>
    </form>
    </div>
    </aside>

    {{-- MAIN WRAPPER --}}
    <div class="main-wrapper">
    {{-- TOPBAR --}}
    <header class="topbar">
    <div class="d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-3">
    <button class="btn btn-light btn-sidebar-toggle" onclick="toggleSidebar()">
    <i class="bi bi-list"></i>
    </button>
    <h5 class="mb-0 fw-semibold">@yield('page_title', 'Dashboard')</h5>
    </div>
    <div class="d-flex align-items-center gap-3">
    {{-- Notifications --}}
    <a href="{{ route('fintech.notifications.index') }}" class="btn btn-light position-relative">
    <i class="bi bi-bell"></i>
    @if(($unreadNotifications ?? 0) > 0)
    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
    {{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}
    </span>
    @endif
    </a>
    {{-- User --}}
    <div class="dropdown">
    <button class="btn btn-light dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem;">
    {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
    </div>
    <span class="d-none d-md-inline">{{ auth()->user()->name ?? 'User' }}</span>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="{{ route('fintech.settings') }}"><i class="bi bi-gear me-2"></i>Pengaturan</a></li>
    <li><hr class="dropdown-divider"></li>
    <li>
    <form action="{{ route('logout') }}" method="POST">
    @csrf
    <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Keluar</button>
    </form>
    </li>
    </ul>
    </div>
    </div>
    </div>
    </header>

    {{-- FLASH MESSAGES --}}
    <div class="content-area">
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show d-flex align-items-center" role="alert">
    <i class="bi bi-check-circle-fill me-2 fs-5"></i>
    <div>{{ session('success') }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
    <div>{{ session('error') }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif
    @if(session('warning'))
    <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center" role="alert">
    <i class="bi bi-exclamation-circle-fill me-2 fs-5"></i>
    <div>{{ session('warning') }}</div>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    @endif

    @yield('content')
    </div>
    </div>

    {{-- Bootstrap JS --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    sidebar.classList.toggle('mobile-show');
    backdrop.classList.toggle('show');
    }

    // Tutup sidebar saat klik link (mobile)
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
    link.addEventListener('click', () => {
    if (window.innerWidth < 992) {
    toggleSidebar();
    }
    });
    });
    </script>
    @stack('scripts')
    </body>
    </html>