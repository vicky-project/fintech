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
      <button class="btn nav-btn" data-page="wallets"><i class="bi bi-wallet2 fs-5"></i></button>
      <button class="btn nav-btn" data-page="reports"><i class="bi bi-graph-up fs-5"></i></button>
      <button class="btn nav-btn" data-page="settings"><i class="bi bi-gear fs-5"></i></button>

      {{-- Dropup untuk menu lebih --}}
      <div class="dropup">
        <button class="btn nav-btn" data-bs-toggle="dropdown" aria-expanded="false">
          <i class="bi bi-three-dots fs-5"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end mb-2">
          <li><button class="dropdown-item nav-btn" data-page="transfers"><i class="bi bi-arrow-left-right me-2"></i>Transfer</button></li>
          <li><button class="dropdown-item nav-btn" data-page="statements"><i class="bi bi-file-text me-2"></i>Statement</button></li>
          <li><button class="dropdown-item nav-btn" data-page="insights"><i class="bi bi-bar-chart me-2"></i>Insight</button></li>
          <li><button class="dropdown-item nav-btn" data-page="budgets"><i class="bi bi-pie-chart me-2"></i>Budget</button></li>
        </ul>
      </div>
    </div>
  </nav>

  {{-- Floating Action Button (Quick Actions) --}}
  <div class="position-fixed bottom-0 end-0 mb-4 me-3" style="z-index: 1000; margin-bottom: 70px !important;">
    <div class="dropup">
      <button id="fab-button" class="btn btn-primary rounded-circle shadow opacity-50" style="width: 56px; height: 56px;" data-bs-toggle="dropdown">
        <i class="bi bi-plus-lg fs-3"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end mb-2">
        <li><a class="dropdown-item" href="#" onclick="showAddWalletModal()"><i class="bi bi-wallet me-2"></i>Tambah Dompet</a></li>
        <li><a class="dropdown-item" href="#" onclick="showAddTransactionModal()"><i class="bi bi-plus-circle me-2"></i>Tambah Transaksi</a></li>
        <li><a class="dropdown-item" href="#" onclick="showAddTransferModal()"><i class="bi bi-send me-2"></i>Transfer</a></li>
        <li><a class="dropdown-item" href="#" onclick="showUploadStatementModal()">
          <i class="bi bi-cloud-upload me-2"></i>Upload Statement</a></li>
        <li><a class="dropdown-item" href="#" onclick="showSuggestCategoryModal()"><i class="bi bi-tags me-2"></i>Category Recomendation</a></li>
      </ul>
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
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const BASE_URL = '{{ rtrim(config("app.url"), "/") }}';

  {!! file_get_contents(module_path('fintech', 'resources/assets/js/app.js')); !!}
</script>
@endpush

@push('styles')
<style>
  #pinModal {
    z-index: 10001 !important;
  }
</style>
@endpush