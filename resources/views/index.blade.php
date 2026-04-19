@extends('telegram::layouts.miniapp')

@section('title', 'FinTech - Dompet Digital')

@section('content')
<div class="container py-3" id="fintech-app">
  {{-- Loading State --}}
  <div id="loading-state" class="text-center py-5">
    <div class="spinner-border text-primary" role="status">
      <span class="visually-hidden">Memuat...</span>
    </div>
    <p class="mt-3 text-muted">
      Mengambil data keuangan...
    </p>
  </div>

  {{-- Main Content (akan diisi oleh JS) --}}
  <div id="main-content" style="display: none;"></div>
</div>

{{-- Modal Tambah Transaksi (Tetap sama seperti sebelumnya) --}}
<div class="modal fade" id="transactionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tambah Transaksi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="transactionForm">
          <div class="mb-3">
            <label class="form-label">Dompet</label>
            <select class="form-select" name="wallet_id" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe</label>
            <select class="form-select" name="type" required>
              <option value="income">Pemasukan</option>
              <option value="expense">Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori</label>
            <select class="form-select" name="category_id" required>
              <option value="">Pilih Kategori</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah (Rp)</label>
            <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal</label>
            <input type="date" class="form-control" name="transaction_date" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi (Opsional)</label>
            <input type="text" class="form-control" name="description">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="saveTransaction()">Simpan</button>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const BASE_URL = '{{ config("app.url") }}';
  // ======================== STATE MANAGEMENT ========================
  const state = {
    wallets: [],
    categories: [],
    recentTransactions: [],
    totalBalance: 0,
    currentWalletId: null
  };

  // ======================== INITIALIZATION ========================
  document.addEventListener('DOMContentLoaded', async () => {
  await initializeApp();
  });

  async function initializeApp() {
    try {
      tgApp.showLoading('Memuat data...');

      // Load data yang diperlukan
      await Promise.all([
      loadWallets(),
      loadCategories()
      ]);

      // Render UI berdasarkan apakah user punya wallet
      renderMainContent();

      tgApp.hideLoading();
      document.getElementById('loading-state').style.display = 'none';
      document.getElementById('main-content').style.display = 'block';
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast('Gagal memuat aplikasi', 'danger');
      console.error(error);
    }
  }

  // ======================== DATA LOADING ========================
  async function loadWallets() {
    try {
      const response = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/wallets');
      state.wallets = response.data || [];

      // Hitung total saldo
      state.totalBalance = state.wallets.reduce((sum, w) => sum + w.balance, 0);

      // Jika ada wallet, load transaksi terbaru
      if (state.wallets.length > 0) {
        await loadRecentTransactions();
      }
    } catch (error) {
      console.error('Gagal load wallets:', error);
      state.wallets = [];
    }
  }

  async function loadCategories() {
    try {
      const response = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/categories');
      state.categories = response.data || [];
    } catch (error) {
      console.error('Gagal load categories:', error);
      state.categories = [];
    }
  }

  async function loadRecentTransactions() {
    try {
      const response = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions?per_page=5');
      state.recentTransactions = response.data.data || [];
    } catch (error) {
      console.error('Gagal load transactions:', error);
      state.recentTransactions = [];
    }
  }

  // ======================== RENDERING ========================
  function renderMainContent() {
    const container = document.getElementById('main-content');

    if (state.wallets.length === 0) {
      // Tidak ada wallet: tampilkan form pembuatan wallet pertama
      container.innerHTML = renderEmptyState();
      // Setup event listener untuk form
      document.getElementById('firstWalletForm').addEventListener('submit', handleCreateFirstWallet);
    } else {
      // Ada wallet: tampilkan dashboard
      container.innerHTML = renderDashboard();
      // Setup UI components
      setupWalletSelector();
      renderWalletList();
      renderRecentTransactions();
      // Load chart setelah elemen ada
      setTimeout(() => loadDoughnutChart(), 100);
    }
  }

  function renderEmptyState() {
    return `
    <div class="text-center mb-4">
    <i class="bi bi-wallet2 display-1 text-primary"></i>
    <h4 class="mt-3">Selamat Datang di FinTech!</h4>
    <p class="text-muted">Anda belum memiliki dompet. Yuk, buat dompet pertama Anda untuk mulai mencatat keuangan.</p>
    </div>

    <div class="card shadow-sm">
    <div class="card-header bg-primary text-white">
    <h5 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Buat Dompet Pertama</h5>
    </div>
    <div class="card-body">
    <form id="firstWalletForm">
    <div class="mb-3">
    <label class="form-label">Nama Dompet <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="name" placeholder="Contoh: Dompet Utama, BCA, Cash" required>
    <small class="text-muted">Beri nama yang mudah diingat.</small>
    </div>
    <div class="mb-3">
    <label class="form-label">Mata Uang</label>
    <select class="form-select" name="currency">
    <option value="IDR" selected>Rupiah (IDR)</option>
    <option value="USD">US Dollar (USD)</option>
    </select>
    </div>
    <div class="mb-3">
    <label class="form-label">Saldo Awal (Rp)</label>
    <input type="number" class="form-control" name="initial_balance" step="0.01" min="0" value="0">
    <small class="text-muted">Isi jika sudah ada uang di dompet ini.</small>
    </div>
    <div class="mb-3">
    <label class="form-label">Deskripsi (Opsional)</label>
    <input type="text" class="form-control" name="description" placeholder="Catatan tambahan">
    </div>
    <button type="submit" class="btn btn-primary w-100">
    <i class="bi bi-check-lg me-1"></i>Buat Dompet
    </button>
    </form>
    </div>
    </div>
    `;
  }

  function renderDashboard() {
    return `
    {{-- Header Saldo Total --}}
    <div class="card bg-gradient-primary text-white mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="card-body">
    <h6 class="card-subtitle mb-2 opacity-75">Total Saldo</h6>
    <h2 class="display-6 fw-bold" id="total-balance-display">Rp ${formatNumber(state.totalBalance)}</h2>
    <small>Semua Dompet Aktif</small>
    </div>
    </div>

    {{-- Pilih Dompet --}}
    <div class="mb-3">
    <label class="form-label fw-semibold">Dompet Aktif</label>
    <select class="form-select" id="wallet-selector">
    <option value="">Semua Dompet</option>
    </select>
    </div>

    {{-- Tab Navigasi --}}
    <ul class="nav nav-tabs mb-3" id="mainTab" role="tablist">
    <li class="nav-item" role="presentation">
    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button">
    <i class="bi bi-house-door"></i> Ringkasan
    </button>
    </li>
    <li class="nav-item" role="presentation">
    <button class="nav-link" id="transactions-tab" data-bs-toggle="tab" data-bs-target="#transactions" type="button">
    <i class="bi bi-list-ul"></i> Transaksi
    </button>
    </li>
    <li class="nav-item" role="presentation">
    <button class="nav-link" id="wallets-tab" data-bs-toggle="tab" data-bs-target="#wallets" type="button">
    <i class="bi bi-wallet2"></i> Dompet
    </button>
    </li>
    </ul>

    {{-- Tab Content --}}
    <div class="tab-content" id="mainTabContent">
    {{-- Tab Ringkasan --}}
    <div class="tab-pane fade show active" id="overview">
    <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Pengeluaran Minggu Ini</h5>
    <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
    <i class="bi bi-arrow-clockwise"></i>
    </button>
    </div>
    <div style="height: 200px;">
    <canvas id="doughnutChart"></canvas>
    </div>

    <h5 class="mt-4">Transaksi Terbaru</h5>
    <div id="recent-transactions-container"></div>
    <div class="text-center mt-2">
    <button class="btn btn-link" onclick="document.getElementById('transactions-tab').click()">
    Lihat Semua <i class="bi bi-arrow-right"></i>
    </button>
    </div>
    </div>

    {{-- Tab Transaksi (Full) --}}
    <div class="tab-pane fade" id="transactions">
    <div class="d-flex justify-content-between mb-3">
    <h5 class="mb-0">Riwayat Transaksi</h5>
    <button class="btn btn-primary btn-sm" onclick="showAddTransactionModal()">
    <i class="bi bi-plus-lg"></i> Tambah
    </button>
    </div>
    <div id="full-transaction-list"></div>
    <div id="transaction-pagination"></div>
    </div>

    {{-- Tab Dompet --}}
    <div class="tab-pane fade" id="wallets">
    <div class="d-flex justify-content-between mb-3">
    <h5 class="mb-0">Daftar Dompet</h5>
    <button class="btn btn-outline-primary btn-sm" onclick="showAddWalletModal()">
    <i class="bi bi-plus-lg"></i> Tambah
    </button>
    </div>
    <div id="wallet-list-container"></div>
    </div>
    </div>
    `;
    }

    // ======================== UI COMPONENT RENDERERS ========================
    function setupWalletSelector() {
    const selector = document.getElementById('wallet-selector');
    if (!selector) return;

    // Clear existing options except first
    selector.innerHTML = '<option value="">Semua Dompet</option>';

    state.wallets.forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    selector.appendChild(option);
    });

    selector.addEventListener('change', async (e) => {
    state.currentWalletId = e.target.value;
    await refreshTransactionData();
    loadDoughnutChart(); // Refresh chart dengan filter wallet
    });
    }

    function renderWalletList() {
    const container = document.getElementById('wallet-list-container');
    if (!container) return;

    if (state.wallets.length === 0) {
    container.innerHTML = '<p class="text-muted text-center">Belum ada dompet</p>';
    return;
    }

    let html = '<div class="list-group">';
    state.wallets.forEach(wallet => {
    html += `
    <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-center">
    <div>
    <h6 class="mb-0">${wallet.name}</h6>
    <small class="text-muted">${wallet.description || '-'}</small>
    </div>
    <div class="text-end">
    <strong>${wallet.formatted_balance}</strong><br>
    <span class="badge bg-${wallet.is_active ? 'success' : 'secondary'}">
    ${wallet.is_active ? 'Aktif' : 'Nonaktif'}
    </span>
    </div>
    </div>
    </div>
    `;
    });
    html += '</div>';
    container.innerHTML = html;
    }

    function renderRecentTransactions() {
    const container = document.getElementById('recent-transactions-container');
    if (!container) return;
    renderTransactionListToContainer(state.recentTransactions, container, false);
    }

    function renderTransactionListToContainer(transactions, container, showPagination = false) {
    if (transactions.length === 0) {
    container.innerHTML = '<p class="text-muted text-center">Belum ada transaksi</p>';
    return;
    }

    let html = '<div class="list-group">';
    transactions.forEach(trx => {
    const sign = trx.type === 'income' ? '+' : '-';
    const amountClass = trx.type === 'income' ? 'text-success' : 'text-danger';

    html += `
    <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-center">
    <div class="d-flex align-items-center">
    <div class="me-3">
    <i class="${trx.category.icon || 'bi-tag'} fs-5" style="color: ${trx.category.color || '#6c757d'}"></i>
    </div>
    <div>
    <div class="fw-semibold">${trx.category.name}</div>
    <small class="text-muted">${trx.description || '-'}</small>
    <div class="small text-muted">
    ${formatDate(trx.transaction_date)} · ${trx.wallet.name}
    </div>
    </div>
    </div>
    <div class="text-end">
    <strong class="${amountClass}">${sign} ${trx.formatted_amount}</strong>
    </div>
    </div>
    </div>
    `;
    });
    html += '</div>';
    container.innerHTML = html;
    }

    // ======================== CHART ========================
    let doughnutChartInstance = null;

    async function loadDoughnutChart() {
    try {
    let url = BASE_URL + '/api/fintech/reports/doughnut-weekly';
    if (state.currentWalletId) {
    url += `?wallet_id=${state.currentWalletId}`;
    }

    const response = await tgApp.fetchWithAuth(url);
    const chartData = response.data;

    const ctx = document.getElementById('doughnutChart')?.getContext('2d');
    if (!ctx) return;

    if (doughnutChartInstance) {
    doughnutChartInstance.destroy();
    }

    if (chartData.labels.length === 0) {
    // Tidak ada data
    return;
    }

    doughnutChartInstance = new Chart(ctx, {
    type: 'doughnut',
    data: {
    labels: chartData.labels,
    datasets: [{
    data: chartData.values,
    backgroundColor: chartData.colors || ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF'],
    borderWidth: 0
    }]
    },
    options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
    legend: {
    position: 'bottom',
    labels: { boxWidth: 12 }
    },
    tooltip: {
    callbacks: {
    label: (context) => {
    const value = context.raw;
    const total = context.dataset.data.reduce((a, b) => a + b, 0);
    const percentage = ((value / total) * 100).toFixed(1);
    return `${context.label}: Rp ${formatNumber(value)} (${percentage}%)`;
    }
    }
    }
    }
    }
    });
    } catch (error) {
    console.error('Gagal load chart:', error);
    }
    }

    // ======================== MODAL & FORM HANDLERS ========================
    function showAddTransactionModal() {
    // Reset form
    document.getElementById('transactionForm').reset();
    document.querySelector('input[name="transaction_date"]').value = new Date().toISOString().split('T')[0];

    // Isi dropdown dompet
    const walletSelect = document.querySelector('select[name="wallet_id"]');
    walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    walletSelect.appendChild(option);
    });

    // Isi dropdown kategori
    const categorySelect = document.querySelector('select[name="category_id"]');
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';
    state.categories.forEach(cat => {
    const option = document.createElement('option');
    option.value = cat.id;
    option.textContent = cat.name;
    categorySelect.appendChild(option);
    });

    new bootstrap.Modal(document.getElementById('transactionModal')).show();
    }

    async function saveTransaction() {
    const form = document.getElementById('transactionForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    // Validasi
    if (!data.wallet_id || !data.category_id || !data.amount) {
    tgApp.showToast('Harap isi semua field wajib', 'danger');
    return;
    }

    try {
    tgApp.showLoading('Menyimpan...');
    await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions', {
    method: 'POST',
    body: JSON.stringify(data)
    });

    tgApp.hideLoading();
    tgApp.showToast('Transaksi berhasil disimpan');
    bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();

    // Refresh data
    await loadWallets();
    await loadRecentTransactions();

    // Update UI
    document.getElementById('total-balance-display').textContent = `Rp ${formatNumber(state.totalBalance)}`;
    setupWalletSelector();
    renderRecentTransactions();
    loadDoughnutChart();

    // Jika di tab transaksi full, refresh juga
    if (document.getElementById('full-transaction-list')) {
    await loadFullTransactions();
    }
    } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menyimpan transaksi', 'danger');
    }
    }

    async function handleCreateFirstWallet(e) {
    e.preventDefault();
    const form = e.target;
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    try {
    tgApp.showLoading('Membuat dompet...');
    await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/wallets', {
    method: 'POST',
    body: JSON.stringify(data)
    });

    tgApp.hideLoading();
    tgApp.showToast('Dompet berhasil dibuat!');

    // Reload halaman untuk menampilkan dashboard
    window.location.reload();
    } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal membuat dompet', 'danger');
    }
    }

    function showAddWalletModal() {
    // Implementasi modal tambah dompet (mirip dengan first wallet tapi dalam modal)
    // Untuk sederhana, kita bisa gunakan prompt atau buat modal terpisah
    // Untuk demo ini, kita arahkan untuk buat dompet via halaman terpisah atau gunakan fungsi yang sudah ada
    alert('Fitur tambah dompet dalam pengembangan. Untuk saat ini, silakan buat dompet baru melalui halaman ini.');
    }

    // ======================== FULL TRANSACTIONS (TAB) ========================
    let currentTransactionPage = 1;

    async function loadFullTransactions(page = 1) {
    try {
    let url = BASE_URL + `/api/fintech/transactions?page=${page}&per_page=10`;
    if (state.currentWalletId) url += `&wallet_id=${state.currentWalletId}`;

    const response = await tgApp.fetchWithAuth(url);
    const data = response.data;

    renderTransactionListToContainer(data.data, document.getElementById('full-transaction-list'), true);

    // Render pagination
    tgApp.renderPagination(
    'transaction-pagination',
    data.current_page,
    data.last_page,
    (newPage) => {
    currentTransactionPage = newPage;
    loadFullTransactions(newPage);
    }
    );
    } catch (error) {
    console.error('Gagal load full transactions:', error);
    }
    }

    async function refreshTransactionData() {
    await loadRecentTransactions();
    renderRecentTransactions();
    if (document.getElementById('full-transaction-list')) {
    await loadFullTransactions(currentTransactionPage);
    }
    }

    // ======================== UTILITY FUNCTIONS ========================
    function formatNumber(num) {
    return new Intl.NumberFormat('id-ID').format(num);
    }

    function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // Event listener untuk tab transaksi full
    document.addEventListener('DOMContentLoaded', () => {
    // Delegate event untuk tab transaksi
    document.addEventListener('shown.bs.tab', (e) => {
    if (e.target.id === 'transactions-tab') {
    loadFullTransactions();
    }
    });
    });
    </script>
    @endpush