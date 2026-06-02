<!DOCTYPE html>
<html lang="id" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'FinTech') — Keuangan Digital</title>

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <style>
    /* ========== FINCH COLOR PALETTE ========== */
:root {
      --fin-primary: #4F46E5;
      --fin-primary-light: #6366F1;
      --fin-primary-dark: #3730A3;
      --fin-primary-bg: #EEF2FF;

      --fin-success: #059669;
      --fin-success-light: #34D399;
      --fin-success-bg: #D1FAE5;

      --fin-danger: #DC2626;
      --fin-danger-light: #F87171;
      --fin-danger-bg: #FEE2E2;

      --fin-warning: #D97706;
      --fin-warning-light: #FBBF24;
      --fin-warning-bg: #FEF3C7;

      --fin-info: #0284C7;
      --fin-info-light: #38BDF8;
      --fin-info-bg: #DBEAFE;

      --sidebar-bg: #0F172A;
      --sidebar-text: #CBD5E1;
      --sidebar-active-bg: rgba(79, 70, 229, 0.25);
      --topbar-bg: #FFFFFF;
      --topbar-border: #E2E8F0;

      --card-bg: #FFFFFF;
      --card-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);

      --chart-1: #4F46E5;
      --chart-2: #059669;
      --chart-3: #DC2626;
      --chart-4: #D97706;
      --chart-5: #0284C7;
      --chart-6: #7C3AED;
      --chart-7: #DB2777;
      --chart-8: #0891B2;

      --sidebar-width: 260px;
    }

    body {
      background: linear-gradient(135deg, #f8fafc 0%, #EEF2FF 50%, #f1f5f9 100%);
      background-attachment: fixed;
      color: #1e293b;
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      overflow-x: hidden;
      }

      /* ========== BOOTSTRAP OVERRIDES ========== */
      .btn-primary { background-color: var(--fin-primary); border-color: var(--fin-primary); }
      .btn-primary:hover, .btn-primary:focus { background-color: var(--fin-primary-light); border-color: var(--fin-primary-light); }
      .btn-primary:active { background-color: var(--fin-primary-dark) !important; border-color: var(--fin-primary-dark) !important; }
      .btn-outline-primary { color: var(--fin-primary); border-color: var(--fin-primary); }
      .btn-outline-primary:hover { background-color: var(--fin-primary); border-color: var(--fin-primary); color: #fff; }
      .btn-success { background-color: var(--fin-success); border-color: var(--fin-success); }
      .btn-outline-success { color: var(--fin-success); border-color: var(--fin-success); }
      .btn-danger { background-color: var(--fin-danger); border-color: var(--fin-danger); }
      .btn-outline-danger { color: var(--fin-danger); border-color: var(--fin-danger); }
      .btn-warning { background-color: var(--fin-warning); border-color: var(--fin-warning); color: #fff; }
      .btn-outline-warning { color: var(--fin-warning); border-color: var(--fin-warning); }

      .text-success { color: var(--fin-success) !important; }
      .text-danger  { color: var(--fin-danger) !important; }
      .text-warning { color: var(--fin-warning) !important; }
      .text-info    { color: var(--fin-info) !important; }
      .text-primary { color: var(--fin-primary) !important; }

      .alert-success { background-color: var(--fin-success-bg); border-color: var(--fin-success-light); color: var(--fin-success); }
      .alert-danger  { background-color: var(--fin-danger-bg); border-color: var(--fin-danger-light); color: var(--fin-danger); }
      .alert-warning { background-color: var(--fin-warning-bg); border-color: var(--fin-warning-light); color: var(--fin-warning); }
      .alert-info    { background-color: var(--fin-info-bg); border-color: var(--fin-info-light); color: var(--fin-info); }

      .badge.bg-success { background-color: var(--fin-success-bg) !important; color: var(--fin-success) !important; }
      .badge.bg-danger  { background-color: var(--fin-danger-bg) !important; color: var(--fin-danger) !important; }
      .badge.bg-warning { background-color: var(--fin-warning-bg) !important; color: var(--fin-warning) !important; }
      .badge.bg-info    { background-color: var(--fin-info-bg) !important; color: var(--fin-info) !important; }

      .progress-bar.bg-success { background-color: var(--fin-success) !important; }
      .progress-bar.bg-danger  { background-color: var(--fin-danger) !important; }
      .progress-bar.bg-warning { background-color: var(--fin-warning) !important; }

      .page-link { color: var(--fin-primary); }
      .page-item.active .page-link { background-color: var(--fin-primary); border-color: var(--fin-primary); }

      .form-select:focus, .form-control:focus {
      border-color: var(--fin-primary-light);
      box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
      }
      .form-check-input:checked { background-color: var(--fin-primary); border-color: var(--fin-primary); }

      /* ========== SIDEBAR ========== */
      .sidebar {
      position: fixed;
      top: 0; bottom: 0; left: 0;
      width: var(--sidebar-width);
      background: linear-gradient(180deg, #1e293b 0%, var(--sidebar-bg) 100%);
      color: var(--sidebar-text);
      z-index: 1040; overflow-y: auto;
      transition: transform 0.3s ease;
      box-shadow: 2px 0 12px rgba(0,0,0,0.15);
      }
      .sidebar .brand {
      display: block; padding: 1.5rem 1.25rem; font-size: 1.35rem; font-weight: 700;
      color: #fff; text-decoration: none;
      border-bottom: 1px solid rgba(255,255,255,0.08); margin-bottom: 0.5rem;
      }
      .sidebar .brand i { color: var(--fin-primary-light); }
      .sidebar .nav-link {
      color: #94a3b8; border-radius: 0.5rem; margin: 0.15rem 0.75rem;
      padding: 0.7rem 1rem; transition: all 0.2s; font-size: 0.925rem;
      }
      .sidebar .nav-link:hover, .sidebar .nav-link.active {
      color: #fff; background: var(--sidebar-active-bg);
      }
      .sidebar .nav-link i { width: 22px; text-align: center; margin-right: 0.75rem; font-size: 1.1rem; }
      .sidebar .nav-section {
      color: #64748b; font-size: 0.7rem; text-transform: uppercase;
      letter-spacing: 0.1em; padding: 1rem 1.25rem 0.35rem; font-weight: 600;
      }

      /* ========== MAIN CONTENT ========== */
      .main-wrapper { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.3s ease; }

      /* ========== TOPBAR ========== */
      .topbar {
      background: var(--topbar-bg); border-bottom: 1px solid var(--topbar-border);
      padding: 0.75rem 1.5rem; position: sticky; top: 0; z-index: 1030;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      }
      .topbar .btn-sidebar-toggle { display: none; }

      /* ========== CONTENT AREA ========== */
      .content-area { padding: 1.75rem; }

      /* ========== CARDS ========== */
      .card-stat {
      background: var(--card-bg); border: 1px solid #e2e8f0; border-radius: 1rem;
      box-shadow: var(--card-shadow); transition: transform 0.2s, box-shadow 0.2s;
      }
      .card-stat:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
      .card-accent-left { border-left: 4px solid var(--fin-primary); }

      /* ========== TABLE ========== */
      .table-fintech th {
      font-weight: 600; font-size: 0.8rem; text-transform: uppercase;
      letter-spacing: 0.05em; color: #64748b; border-bottom-width: 2px;
      }
      .table-fintech td { vertical-align: middle; padding: 0.75rem 1rem; }

      /* ========== BADGE SOFT ========== */
      .badge-soft-success { background: var(--fin-success-bg); color: var(--fin-success); }
      .badge-soft-danger  { background: var(--fin-danger-bg); color: var(--fin-danger); }
      .badge-soft-warning { background: var(--fin-warning-bg); color: var(--fin-warning); }
      .badge-soft-info    { background: var(--fin-info-bg); color: var(--fin-info); }

      /* ========== MOBILE ========== */
      .sidebar-backdrop {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,0.4); backdrop-filter: blur(2px); z-index: 1035;
      }
      .sidebar-backdrop.show { display: block; }
      @media (max-width: 991.98px) {
      .sidebar { transform: translateX(-100%); }
      .sidebar.mobile-show { transform: translateX(0); }
      .main-wrapper { margin-left: 0; }
      .topbar .btn-sidebar-toggle { display: inline-block; }
      }

      .text-truncate-2 {
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
      }
      .cursor-pointer { cursor: pointer; }
      </style>
      @stack('styles')
      </head>
      <body>

      <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="toggleSidebar()"></div>

      <aside class="sidebar" id="sidebar">
      <a href="{{ config('app.url') }}" class="brand">
      <i class="bi bi-cash-stack me-2"></i>FinTech
      </a>
      <nav class="nav flex-column">
      <div class="nav-section">Menu Utama</div>
      @php
      $menuItems = [
      ['route' => 'fintech.home',                'icon' => 'bi-speedometer2',        'label' => 'Dashboard'],
      ['route' => 'fintech.transactions.index',  'icon' => 'bi-list-ul',              'label' => 'Transaksi'],
      ['route' => 'fintech.wallets.index',       'icon' => 'bi-wallet2',              'label' => 'Dompet'],
      ['route' => 'fintech.transfers.index',     'icon' => 'bi-arrow-left-right',     'label' => 'Transfer'],
      ['route' => 'fintech.budgets.index',       'icon' => 'bi-pie-chart',            'label' => 'Budget'],
      ];
      $menuTools = [
      ['route' => 'fintech.reports.index',       'icon' => 'bi-graph-up',             'label' => 'Laporan'],
      ['route' => 'fintech.statements.index',    'icon' => 'bi-file-earmark-text',    'label' => 'Statement'],
      ['route' => 'fintech.insights.index',      'icon' => 'bi-lightbulb',            'label' => 'Insight'],
      ['route' => 'fintech.zakat.index',         'icon' => 'bi-calculator',           'label' => 'Zakat & Pajak'],
      ['route' => 'fintech.export.index',        'icon' => 'bi-cloud-download',       'label' => 'Export'],
      ];
      $menuOther = [
      ['route' => 'fintech.notifications.index', 'icon' => 'bi-bell',                 'label' => 'Notifikasi'],
      ['route' => 'fintech.settings',            'icon' => 'bi-sliders',               'label' => 'Pengaturan'],
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
      <a href="{{ route(config('fintech.back_home_route', 'fintech.home')) }}" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a>
      </div>
      </aside>

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
      <div class="d-flex align-items-center gap-1">
      {{-- Ikon Pencarian Global --}}
      <a href="{{ route('fintech.search') }}" class="btn btn-light btn-sm" title="Pencarian">
      <i class="bi bi-search"></i>
      </a>
      {{-- Notifications --}}
      <a href="{{ route('fintech.notifications.index') }}" class="btn btn-light btn-sm position-relative">
      <i class="bi bi-bell"></i>
      @if(($unreadNotifications ?? 0) > 0)
      <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
      {{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}
      </span>
      @endif
      </a>
      {{-- User Dropdown --}}
      <div class="dropdown">
      <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown">
      <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width: 32px; height: 32px; font-size: 0.8rem;">
      {{ strtoupper(substr(auth()->user()->name ?? 'U', 0, 1)) }}
      </div>
      <span class="d-none d-md-inline">{{ auth()->user()->name ?? 'User' }}</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
      <li><a class="dropdown-item" href="{{ route('fintech.settings') }}"><i class="bi bi-gear me-2"></i>Pengaturan</a></li>
      <li><hr class="dropdown-divider"></li>
      <li>
      <a class="dropdown-item text-danger" href="{{ route(config('fintech.back_home_route', 'fintech.home')) }}"><i class="bi bi-box-arrow-right me-2"></i>Keluar</a>
      </li>
      </ul>
      </div>
      </div>
      </div>
      </header>

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

      <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
      <script>
      function toggleSidebar() {
      const sidebar = document.getElementById('sidebar');
      const backdrop = document.getElementById('sidebarBackdrop');
      sidebar.classList.toggle('mobile-show');
      backdrop.classList.toggle('show');
      }
      document.querySelectorAll('.sidebar .nav-link').forEach(link => {
      link.addEventListener('click', () => {
      if (window.innerWidth < 992) toggleSidebar();
      });
      });
      </script>
      @stack('scripts')
      </body>
      </html>