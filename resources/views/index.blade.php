@extends('telegram::layouts.mini-app')

@section('title', 'FinTech - Keuangan Digital')

@section('content')
<div id="fintech-app">
  {{-- Loading overlay --}}
  <div id="loading-overlay" class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-white bg-opacity-75" style="z-index: 9999;">
    <div class="text-center">
      <div class="spinner-border text-primary mb-3" role="status"></div>
      <p class="text-muted">
        Memuat data keuangan...
      </p>
    </div>
  </div>

  {{-- Main content area --}}
  <div id="main-content" class="pb-5"></div>

  {{-- Footer --}}
  <footer class="text-center text-muted small py-3 mt-auto" style="padding-bottom: 120px !important;">
    <p class="mb-1">
      FinTech — Catat keuangan, kendalikan masa depan.
    </p>
    <p class="mb-0">
      &copy; {{ date('Y') }} FinTech App
    </p>
  </footer>

  {{-- Bottom Navigation --}}
  <nav class="navbar navbar-light bg-light fixed-bottom border-top">
    <div class="container-fluid justify-content-around">
      <button class="btn nav-btn" data-page="home"><i class="bi bi-house fs-5"></i></button>
      <button class="btn nav-btn" data-page="transactions"><i class="bi bi-list-ul fs-5"></i></button>
      <button class="btn nav-btn" data-page="reports"><i class="bi bi-graph-up fs-5"></i></button>
      <button class="btn nav-btn" data-page="search"><i class="bi bi-search fs-5"></i></button>
      <button class="btn nav-btn position-relative" data-page="notifications">
        <i class="bi bi-bell fs-5"></i>
        <span id="notification-badge"
          class="badge bg-danger rounded-circle position-absolute"
          style="display: none;
          font-size: 0.6rem;
          width: 14px;
          height: 14px;
          line-height: 14px;
          text-align: center;
          padding: 0;
          top: 7px;
          right: 7px;">
        </span>
      </button>
      <button class="btn nav-btn" data-page="settings"><i class="bi bi-gear fs-5"></i></button>

      {{-- Dropup untuk menu lebih --}}
      <div class="dropup">
        <button class="btn nav-btn" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-three-dots fs-5"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end mb-2">
          <li><button class="dropdown-item nav-btn" data-page="wallets"><i class="bi bi-wallet2 me-2"></i>Dompet</button></li>
          <li><button class="dropdown-item nav-btn" data-page="transfers"><i class="bi bi-arrow-left-right me-2"></i>Transfer</button></li>
          <li><button class="dropdown-item nav-btn" data-page="statements"><i class="bi bi-file-text me-2"></i>Statement</button></li>
          <li><button class="dropdown-item nav-btn" data-page="insights"><i class="bi bi-bar-chart me-2"></i>Insight</button></li>
          <li><button class="dropdown-item nav-btn" data-page="budgets"><i class="bi bi-pie-chart me-2"></i>Budget</button></li>
          <li><button class="dropdown-item nav-btn" data-page="export"><i class="bi bi-cloud-download-fill me-2"></i>Export</button></li>
        </ul>
      </div>
    </div>
  </nav>

  {{-- Floating Action Button Modern --}}
  <div class="position-fixed bottom-0 end-0 mb-4 me-3" style="z-index: 1000; margin-bottom: 70px !important;">
    <button id="fab-button" class="btn btn-primary rounded-circle shadow" style="width: 56px; height: 56px; opacity: 0.8; transition: transform 0.3s ease;" onclick="toggleQuickActions()">
      <i id="fab-icon" class="bi bi-plus-lg fs-3"></i>
    </button>
  </div>

  {{-- Overlay Quick Actions --}}
  <div id="quick-actions-overlay" class="position-fixed top-0 start-0 w-100 h-100" style="z-index: 9999; background-color: rgba(0,0,0,0.4); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); opacity: 0; pointer-events: none; transition: opacity 0.3s ease;" data-action="toggle-quick-actions">
    <div class="d-flex flex-column justify-content-end h-100 pb-5" onclick="event.stopPropagation()">
      <div class="container px-4 pb-5">
        <div class="d-flex justify-content-center flex-wrap gap-4 mb-4">
          <!-- Tombol-tombol aksi hanya ikon -->
          <button class="quick-action-btn btn btn-light rounded-circle shadow-lg d-flex align-items-center justify-content-center"
            style="width: 64px; height: 64px; transition: transform 0.2s ease, box-shadow 0.2s ease;"
            onclick="showAddWalletModal(); toggleQuickActions();"
            title="Tambah Dompet">
            <i class="bi bi-wallet-fill fs-3 text-primary"></i>
          </button>
          <button class="quick-action-btn btn btn-light rounded-circle shadow-lg d-flex align-items-center justify-content-center"
            style="width: 64px; height: 64px; transition: transform 0.2s ease, box-shadow 0.2s ease;"
            onclick="showAddTransactionModal(); toggleQuickActions();"
            title="Tambah Transaksi">
            <i class="bi bi-plus-circle-fill fs-3 text-success"></i>
          </button>
          <button class="quick-action-btn btn btn-light rounded-circle shadow-lg d-flex align-items-center justify-content-center"
            style="width: 64px; height: 64px; transition: transform 0.2s ease, box-shadow 0.2s ease;"
            onclick="showAddTransferModal(); toggleQuickActions();"
            title="Transfer">
            <i class="bi bi-send-fill fs-3 text-info"></i>
          </button>
          <button class="quick-action-btn btn btn-light rounded-circle shadow-lg d-flex align-items-center justify-content-center"
            style="width: 64px; height: 64px; transition: transform 0.2s ease, box-shadow 0.2s ease;"
            onclick="showUploadStatementModal(); toggleQuickActions();"
            title="Upload Statement">
            <i class="bi bi-cloud-upload-fill fs-3 text-warning"></i>
          </button>
          <button class="quick-action-btn btn btn-light rounded-circle shadow-lg d-flex align-items-center justify-content-center"
            style="width: 64px; height: 64px; transition: transform 0.2s ease, box-shadow 0.2s ease;"
            onclick="showSuggestCategoryModal(); toggleQuickActions();"
            title="Usulkan Kategori">
            <i class="bi bi-tags-fill fs-3 text-secondary"></i>
          </button>
        </div>
        <div class="text-center">
          <button class="btn btn-outline-light rounded-circle" style="width: 48px; height: 48px;" onclick="toggleQuickActions()">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="transactionDetailModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Detail Transaksi</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="transactionDetailBody"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
        </div>
      </div>
    </div>
  </div>

  {{-- Modal Filter Laporan --}}
  <div class="modal fade" id="reportFilterModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Filter Laporan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Dompet</label>
            <select class="form-select" id="filter-wallet">
              <option value="">Semua Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Periode</label>
            <select class="form-select" id="filter-period-type">
              <option value="monthly">Bulanan</option>
              <option value="yearly">Tahunan</option>
              <option value="all_years">Semua Tahun</option>
            </select>
          </div>
          <div class="mb-3" id="filter-period-detail">
            {{-- Akan diisi oleh JS sesuai tipe --}}
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="button" class="btn btn-primary" onclick="applyReportFilter()">Terapkan</button>
        </div>
      </div>
    </div>
  </div>

  {{-- Modal Hapus Massal --}}
  <div class="modal fade" id="bulkDeleteModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Hapus Transaksi Massal</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-danger">
            Peringatan: Semua transaksi pada dompet dan bulan yang dipilih akan dipindahkan ke tempat sampah.
          </p>
          <div class="mb-3">
            <label class="form-label">Dompet <span class="text-danger">*</span></label>
            <select class="form-select" id="bulk-wallet" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Pilih Bulan <span class="text-danger">*</span></label>
            <input type="month" class="form-control" id="bulk-month" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="button" class="btn btn-danger" onclick="executeBulkDelete()">Hapus</button>
        </div>
      </div>
    </div>
  </div>

  {{-- Modal Detail Hasil Pencarian --}}
  <div class="modal fade" id="searchDetailModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="searchDetailModalTitle">Detail</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="searchDetailBody"></div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
          <button type="button" class="btn btn-primary" id="searchDetailActionBtn" style="display:none;">Lihat</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Backup -->
  <div class="modal fade" id="backupPasswordModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h6 class="modal-title">🔒 Enkripsi Backup (opsional)</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="small">
            Lindungi file backup dengan password. Anda harus mengingat password ini untuk restore.
          </p>
          <input type="password" class="form-control" id="backup-password" placeholder="Password (min. 4 karakter)" minlength="4">
          <small class="text-muted">Kosongkan jika tidak ingin mengenkripsi.</small>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
          <button type="button" class="btn btn-primary" id="btn-backup-confirm">Backup Sekarang</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal Restore -->
  <div class="modal fade" id="restoreModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0">
          <h6 class="modal-title"><i class="bi bi-cloud-upload me-2"></i>Pulihkan Data</h6>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center py-3">
          <i class="bi bi-file-earmark-zip text-warning mb-3" style="font-size: 3rem;"></i>
          <p class="text-muted">
            Pilih file backup (.json.gz) yang sebelumnya Anda unduh untuk mengembalikan semua data keuangan.
          </p>
          <form id="formRestore">
            <div class="mb-3">
              <input class="form-control" type="file" name="backup_file" id="restore-file-input" data-action="restore-input-change">
              <small class="text-muted mt-1 d-block">File harus merupakan backup dari akun Telegram Anda.</small>
            </div>
            <div class="mb-3">
              <input type="password" class="form-control" id="restore-password" placeholder="Password (jika file terenkripsi)" minlength="4">
              <span class="small mb-0 text-muted d-block">Kosongkan jika tidak dilindungi password.</span>
            </div>
          </form>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="button" class="btn btn-warning" id="btn-upload-restore" data-action="restore-data" disabled>
            <i class="bi bi-upload me-1"></i> Upload & Pulihkan
          </button>
        </div>
      </div>
    </div>
  </div>

  {{-- Modal Verifikasi PIN --}}
  <div class="modal fade" id="pinModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-sm modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Masukkan PIN</h5>
        </div>
        <div class="modal-body">
          <p class="text-muted small">
            PIN diperlukan untuk membuka aplikasi.
          </p>
          <form id="pinForm">
            <div class="mb-3">
              <input type="password" class="form-control form-control-lg text-center"
              id="pinInput" name="pin" inputmode="numeric"
              pattern="[0-9]*" maxlength="6" minlength="4" required
              placeholder="Masukkan PIN" autofocus>
            </div>
            <div id="pinError" class="text-danger small mb-2 d-none">
              PIN salah. Silakan coba lagi.
            </div>
            <div id="pinLockedInfo" class="text-danger small mb-2 d-none"></div>
            <button type="submit" class="btn btn-primary w-100">Verifikasi</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Modals --}}
@include('fintech::partials.modals.wallet')
@include('fintech::partials.modals.transaction')
@include('fintech::partials.modals.transfer')
@include('fintech::partials.modals.suggest-category')
@include('fintech::partials.modals.upload-statement')
@include('fintech::partials.modals.budget')
@include('fintech::partials.modals.delete-account')
@endsection

@push('scripts')
<script src="//cdn.jsdelivr.net/npm/eruda"></script>
<script>
  eruda.init(); // Ikon Eruda akan muncul
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const BASE_URL = '{{ rtrim(config("app.url"), "/") }}';

  const BOT_USERNAME = '{{ config("telegram.bot.username") }}'; // GANTI dengan username bot asli

  {!! file_get_contents(module_path('fintech', 'resources/assets/js/core.js')); !!}
  {!! file_get_contents(module_path('fintech', 'resources/assets/js/page.js')); !!}
  {!! file_get_contents(module_path('fintech', 'resources/assets/js/main.js')); !!}
</script>
@endpush

@push('styles')
<style>
  /* ----------------------------- */
  /* MODAL & OVERLAY               */
  /* ----------------------------- */
  #pinModal {
    z-index: 10001 !important;
  }

  /* ----------------------------- */
  /* QUICK ACTION BUTTONS          */
  /* ----------------------------- */
  .quick-action-btn:hover {
    transform: scale(1.15);
    box-shadow: 0 8px 25px rgba(255,255,255,0.3) !important;
  }

  /* ----------------------------- */
  /* DROPDOWN & NAV                */
  /* ----------------------------- */
  .dropdown-menu {
    background-color: var(--tg-theme-secondary-bg-color) !important;
    color: var(--tg-theme-text-color) !important;
    border: 1px solid var(--tg-theme-section-separator-color) !important;
  }

  .dropdown-menu .dropdown-item {
    color: var(--tg-theme-text-color) !important;
  }

  .dropdown-menu .dropdown-item:hover,
  .dropdown-menu .dropdown-item:focus {
    background-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
  }

  .dropdown-menu .dropdown-item.active,
  .dropdown-menu .dropdown-item:active {
    background-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
  }

  .dropdown-divider {
    border-top-color: var(--tg-theme-section-separator-color) !important;
  }

  /* ----------------------------- */
  /* SEARCH                        */
  /* ----------------------------- */
  #search-input-group .input-group-text {
    background-color: var(--tg-theme-secondary-bg-color);
    color: var(--tg-theme-text-color);
    border-right: none;
  }

  #search-input {
    border-left: none;
  }

  .search-filter-btn.active {
    background-color: var(--tg-theme-button-color) !important;
    color: var(--tg-theme-button-text-color) !important;
    border-color: var(--tg-theme-button-color) !important;
  }

  .search-filter-btn {
    color: var(--tg-theme-button-color);
    border-color: var(--tg-theme-button-color);
  }

  .filter-badge {
    margin-left: 4px;
    font-size: 0.7rem;
  }

  .search-result-item {
    border-radius: 12px;
    margin-bottom: 8px;
    transition: background-color 0.2s;
  }

  .search-result-item:hover {
    background-color: var(--tg-theme-hint-color, rgba(0,0,0,0.03));
  }

  /* ----------------------------- */
  /* NOTIFICATIONS                 */
  /* ----------------------------- */
  .notification-title {
    color: var(--tg-theme-text-color) !important;
    font-weight: 600;
  }

  .notification-row.read .notification-title {
    opacity: 0.7;
  }

  .notification-message {
    color: var(--tg-theme-hint-color);
    font-size: 0.875rem;
  }

  .notification-time {
    font-size: 0.75rem;
    color: var(--tg-theme-hint-color);
    white-space: nowrap;
    margin-left: 8px;
  }

  .notification-row {
    transition: background-color 0.2s;
    position: relative;
    /* agar badge absolute bisa diposisikan */
  }

  .notification-row:hover {
    background-color: var(--tg-theme-hint-color, rgba(0,0,0,0.03));
  }

  .notification-row.unread {
    border-left: 3px solid var(--tg-theme-button-color, #007aff);
    }

    .notification-row.unread::before {
    content: '';
    width: 8px;
    height: 8px;
    background: var(--tg-theme-button-color, #007aff);
    border-radius: 50%;
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    }

    .notification-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4px;
    }

    .notification-icon {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    flex-shrink: 0;
    }

    .notification-icon.budget-warning { background-color: rgba(255, 193, 7, 0.15); color: #f0ad4e; }
    .notification-icon.cashflow-warning { background-color: rgba(220, 53, 69, 0.15); color: #dc3545; }
    .notification-icon.subscription-reminder { background-color: rgba(13, 110, 253, 0.15); color: #0d6efd; }

    /* ----------------------------- */
    /* FORM ELEMENTS                 */
    /* ----------------------------- */
    .form-select,
    .form-control,
    .modal-content {
    background-color: var(--tg-theme-bg-color) !important;
    color: var(--tg-theme-text-color) !important;
    border-color: var(--tg-theme-hint-color) !important;
    }

    /* Saat form element fokus, kita beri highlight dengan warna tombol */
    .form-select:focus,
    .form-control:focus,
    .form-check-input:focus {
    border-color: var(--tg-theme-button-color) !important;
    box-shadow: 0 0 0 0.25rem rgba(var(--tg-theme-button-color), 0.25) !important;
    /* Untuk browser yang mendukung color-mix, gunakan yang lebih halus */
    box-shadow: 0 0 0 0.25rem color-mix(in srgb, var(--tg-theme-button-color) 25%, transparent) !important;
    }

    /* Efek hover pada input */
    .form-select:hover,
    .form-control:hover {
    border-color: var(--tg-theme-button-color);
    }

    /* Accordion dalam tema */
    .accordion-button {
    background-color: var(--tg-theme-secondary-bg-color);
    color: var(--tg-theme-text-color);
    }

    .accordion-button:not(.collapsed) {
    background-color: var(--tg-theme-button-color);
    color: var(--tg-theme-button-text-color);
    }

    .accordion-item {
    background-color: var(--tg-theme-bg-color);
    border-color: var(--tg-theme-hint-color);
    }

    /* Placeholder */
    ::placeholder {
    color: var(--tg-theme-hint-color) !important;
    opacity: 0.7;
    }

    /* Card */
    .card {
    background-color: var(--tg-theme-bg-color);
    border-color: var(--tg-theme-hint-color);
    }

    /* Badge (misal untuk filter) */
    .badge {
    color: var(--tg-theme-button-text-color);
    }

    /* Background gradient untuk header card bisa disesuaikan */
    .bg-gradient-primary {
    background: linear-gradient(135deg, var(--tg-theme-button-color), var(--tg-theme-secondary-bg-color)) !important;
    color: var(--tg-theme-button-text-color);
    }
    </style>
    @endpush