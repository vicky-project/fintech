@extends('telegram::layouts.mini-app')

@section('title', 'FinTech - Keuangan Digital')

@section('content')
<div id="fintech-app">
  {{-- Loading overlay --}}
  <div id="loading-overlay" class="position-fixed top-0 start-0 w-100 h-100 d-flex align-items-center justify-content-center bg-white bg-opacity-75" style="z-index: 9999;">
    <div class="spinner-border text-primary" role="status">
      <span class="visually-hidden">Memuat...</span>
    </div>
  </div>

  {{-- Main content area --}}
  <div id="main-content" class="pb-5"></div>

  {{-- Bottom Navigation --}}
  <nav class="navbar navbar-light bg-light fixed-bottom border-top">
    <div class="container-fluid justify-content-around">
      <button class="btn nav-btn active" data-page="home"><i class="bi bi-house fs-5"></i></button>
      <button class="btn nav-btn" data-page="transactions"><i class="bi bi-list-ul fs-5"></i></button>
      <button class="btn nav-btn" data-page="wallets"><i class="bi bi-wallet2 fs-5"></i></button>
      <button class="btn nav-btn" data-page="reports"><i class="bi bi-bar-chart fs-5"></i></button>
    </div>
  </nav>

  {{-- Floating Action Button (Quick Actions) --}}
  <div class="position-fixed bottom-0 end-0 mb-5 me-3" style="z-index: 1000;">
    <div class="dropup">
      <button class="btn btn-primary rounded-circle shadow" style="width: 56px; height: 56px;" data-bs-toggle="dropdown">
        <i class="bi bi-plus-lg fs-3"></i>
      </button>
      <ul class="dropdown-menu dropdown-menu-end mb-2">
        <li><a class="dropdown-item" href="#" onclick="showAddWalletModal()"><i class="bi bi-wallet me-2"></i>Tambah Dompet</a></li>
        <li><a class="dropdown-item" href="#" onclick="showAddTransactionModal()"><i class="bi bi-arrow-left-right me-2"></i>Tambah Transaksi</a></li>
        <li><a class="dropdown-item" href="#" onclick="navigateToTrash()"><i class="bi bi-trash me-2"></i>Tempat Sampah</a></li>
      </ul>
    </div>
  </div>
</div>

{{-- Modals --}}
@include('fintech::partials.modals.wallet')
@include('fintech::partials.modals.transaction')
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const BASE_URL = '{{ rtrim(config("app.url"), "/") }}';

  // State management
  const state = {
    wallets: [],
    categories: [],
    currencies: [],
    allTransactions: [],
    recentTransactions: [],
    totalBalance: 0,
    currentPage: 'home',
    filters: {
      wallet_id: '',
      type: '',
      month: ''
    },
    chartInstances: {},
    defaultCurrency: 'IDR'
  };

  // ==================== INITIALIZATION ====================
  document.addEventListener('DOMContentLoaded', async () => {
  await initializeApp();
  setupNavigation();
  });

  async function initializeApp() {
    const loadingOverlay = document.getElementById('loading-overlay');
    const mainContent = document.getElementById('main-content');

    try {
      // Tampilkan loading
      loadingOverlay.style.display = 'flex';
      loadingOverlay.innerHTML = `
      <div class="text-center">
      <div class="spinner-border text-primary mb-3" role="status"></div>
      <p class="text-muted">Memuat data keuangan...</p>
      </div>
      `;

      // Load data penting
      await Promise.all([
      loadWallets().catch(e => { throw new Error('Gagal memuat dompet: ' + e.message); }),
      loadCategories().catch(e => { throw new Error('Gagal memuat kategori: ' + e.message); }),
      loadCurrencies().catch(e => { throw new Error('Gagal memuat mata uang: ' + e.message); })
      ]);

      // Jika punya wallet, load transaksi
      if (state.wallets.length > 0) {
        await loadAllTransactions().catch(e => {
        tgApp.showToast('Gagal memuat transaksi terbaru', 'warning');
        state.allTransactions = [];
        });
      }

      // Navigasi ke halaman awal
      navigateTo('home');

      // Sembunyikan loading
      loadingOverlay.style.display = 'none';
    } catch (error) {
      console.error('Init error:', error);

      // Tampilkan pesan error di dalam overlay
      loadingOverlay.innerHTML = `
      <div class="text-center p-4">
      <i class="bi bi-exclamation-triangle text-danger display-4"></i>
      <h5 class="mt-3">Gagal Memuat Aplikasi</h5>
      <p class="text-muted">${error.message || 'Terjadi kesalahan tidak diketahui.'}</p>
      <button class="btn btn-primary mt-2" onclick="retryInitialization()">
      <i class="bi bi-arrow-clockwise me-2"></i>Coba Lagi
      </button>
      </div>
      `;
      loadingOverlay.style.display = 'flex';
    }
  }

  // Fungsi untuk mencoba ulang inisialisasi
  function retryInitialization() {
    initializeApp();
  }

  function setupNavigation() {
    document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', () => navigateTo(btn.dataset.page));
    });
  }

  // ==================== DATA LOADING ====================
  async function loadWallets() {
    try {
      const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/wallets');
      state.wallets = res.data || [];
      state.totalBalance = state.wallets.reduce((s, w) => s + w.balance, 0);
    } catch (error) {
      console.error('loadWallets error:', error);
      state.wallets = [];
      state.totalBalance = 0;
      throw new Error('Tidak dapat menghubungi server. Periksa koneksi Anda.');
    }
  }

  async function loadCategories() {
    try {
      const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/categories');
      state.categories = res.data || [];
    } catch(error) {
      console.error('loadCategories error:', error);
      state.categories = [];
      throw new Error('Tidak dapat menghubungi server. Periksa koneksi Anda.');
    }
  }

  async function loadCurrencies() {
    try {
      const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/currencies');
      state.currencies = res.data || [];
    } catch(error) {
      console.error('loadCurrencies error:', error);
      state.currencies = [];
      throw new Error('Tidak dapat menghubungi server. Periksa koneksi Anda.');
    }
  }

  async function loadAllTransactions() {
    try {
      const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions?per_page=100');
      state.allTransactions = res.data.data || [];
      state.recentTransactions = state.allTransactions.slice(0, 5);
    } catch(error) {
      console.error('loadAllTransactions error:', error);
      state.allTransactions = [];
      state.recentTransactions = [];
      throw new Error('Tidak dapat menghubungi server. Periksa koneksi Anda.');
    }
  }

  // ==================== NAVIGATION ====================
  function navigateTo(page) {
    state.currentPage = page;
    document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.page === page);
    });

    const container = document.getElementById('main-content');
    if (state.wallets.length === 0) {
      container.innerHTML = renderEmptyState();
      return;
    }

    switch (page) {
      case 'home': renderHomePage(); break;
      case 'transactions': renderTransactionsPage(); break;
      case 'wallets': renderWalletsPage(); break;
      case 'reports': renderReportsPage(); break;
    }
  }

  function navigateToTrash() {
    state.currentPage = 'trash';
    renderTrashPage();
    document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
  }

  // ==================== EMPTY STATE ====================
  function renderEmptyState() {
    return `
    <div class="container py-4 text-center">
    <i class="bi bi-wallet2 display-1 text-primary"></i>
    <h4 class="mt-3">Belum Ada Dompet</h4>
    <p>Buat dompet pertama untuk mulai mencatat keuangan.</p>
    <button class="btn btn-primary" onclick="showAddWalletModal()">
    <i class="bi bi-plus-circle"></i> Buat Dompet
    </button>
    </div>
    `;
  }

  // ==================== HOME PAGE ====================
  function renderHomePage() {
    const symbol = getCurrencySymbol(state.wallets[0]?.currency || 'IDR');
    const html = `
    <div class="container py-3">
    <div class="card bg-gradient-primary text-white mb-3" style="background: linear-gradient(135deg, #667eea, #764ba2);">
    <div class="card-body">
    <h6>Total Saldo</h6>
    <h2>${symbol} ${formatNumber(state.totalBalance)}</h2>
    <small>${state.wallets.length} dompet aktif</small>
    </div>
    </div>

    <div class="row g-2 mb-3">
    <div class="col-6">
    <div class="card">
    <div class="card-body p-3 text-center">
    <i class="bi bi-arrow-down-circle text-success fs-4"></i>
    <h6 class="mb-0">${formatNumber(getTotalIncome())}</h6>
    <small>Pemasukan</small>
    </div>
    </div>
    </div>
    <div class="col-6">
    <div class="card">
    <div class="card-body p-3 text-center">
    <i class="bi bi-arrow-up-circle text-danger fs-4"></i>
    <h6 class="mb-0">${formatNumber(getTotalExpense())}</h6>
    <small>Pengeluaran</small>
    </div>
    </div>
    </div>
    </div>

    <div class="card mb-3">
    <div class="card-body">
    <h6>Pengeluaran Mingguan</h6>
    <div style="height: 180px;"><canvas id="homeChart"></canvas></div>
    </div>
    </div>

    <h6>Transaksi Terbaru</h6>
    <div id="recent-transactions"></div>
    </div>
    `;
    document.getElementById('main-content').innerHTML = html;
    setTimeout(() => { loadHomeChart(); renderRecentTransactions(); }, 50);
    }

    function getTotalIncome() {
    return state.allTransactions.filter(t => t.type === 'income').reduce((s, t) => s + t.amount, 0);
    }

    function getTotalExpense() {
    return state.allTransactions.filter(t => t.type === 'expense').reduce((s, t) => s + t.amount, 0);
    }

    function renderRecentTransactions() {
    const container = document.getElementById('recent-transactions');
    if (!container) return;
    if (state.recentTransactions.length === 0) {
    container.innerHTML = '<p class="text-muted text-center">Belum ada transaksi</p>';
    return;
    }
    container.innerHTML = state.recentTransactions.map(trx => `
    <div class="d-flex justify-content-between align-items-center border-bottom py-2">
    <div><i class="${trx.category.icon} me-2" style="color:${trx.category.color}"></i>${trx.category.name}</div>
    <span class="${trx.type === 'income' ? 'text-success' : 'text-danger'}">${trx.formatted_amount}</span>
    </div>
    `).join('');
    }

    async function loadHomeChart() {
    try {
    const ctx = document.getElementById('homeChart')?.getContext('2d');
    if (!ctx) return;
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/reports/doughnut-weekly');
    const data = res.data;
    if (state.chartInstances.home) state.chartInstances.home.destroy();
    state.chartInstances.home = new Chart(ctx, { type: 'doughnut', data: { labels: data.labels, datasets: [{ data: data.values, backgroundColor: data.colors }] } });
    } catch(error) {
    alert(error.message);
    }
    }

    // ==================== TRANSACTIONS PAGE ====================
    function renderTransactionsPage() {
    const html = `
    <div class="container py-3">
    <div class="d-flex justify-content-between mb-3">
    <h5>Transaksi</h5>
    <div>
    <button class="btn btn-sm btn-outline-secondary me-1" onclick="navigateToTrash()"><i class="bi bi-trash"></i></button>
    <button class="btn btn-sm btn-primary" onclick="showAddTransactionModal()"><i class="bi bi-plus"></i></button>
    </div>
    </div>
    <div class="row g-2 mb-3" id="transaction-stats"></div>
    <div class="mb-3">
    <select class="form-select form-select-sm" id="filter-wallet" onchange="applyTransactionFilter()">
    <option value="">Semua Dompet</option>
    ${state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
    </select>
    </div>
    <div id="transaction-list"></div>
    </div>
    `;
    document.getElementById('main-content').innerHTML = html;
    updateTransactionStats();
    renderTransactionList();
    }

    function getFilteredTransactions() {
    let filtered = state.allTransactions;
    if (state.filters.wallet_id) filtered = filtered.filter(t => t.wallet_id == state.filters.wallet_id);
    if (state.filters.type) filtered = filtered.filter(t => t.type === state.filters.type);
    return filtered;
    }

    function updateTransactionStats() {
    const filtered = getFilteredTransactions();
    const income = filtered.filter(t => t.type === 'income').reduce((s, t) => s + t.amount, 0);
    const expense = filtered.filter(t => t.type === 'expense').reduce((s, t) => s + t.amount, 0);
    const symbol = getCurrencySymbol(state.defaultCurrency);
    document.getElementById('transaction-stats').innerHTML = `
    <div class="col-4"><div class="card p-2 text-center"><small>Total</small><strong>${filtered.length}</strong></div></div>
    <div class="col-4"><div class="card p-2 text-center text-success"><small>Masuk</small><strong>${symbol}${formatNumber(income)}</strong></div></div>
    <div class="col-4"><div class="card p-2 text-center text-danger"><small>Keluar</small><strong>${symbol}${formatNumber(expense)}</strong></div></div>
    `;
    }

    function renderTransactionList() {
    const filtered = getFilteredTransactions();
    const container = document.getElementById('transaction-list');
    if (filtered.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Tidak ada transaksi</p>';
    return;
    }
    container.innerHTML = filtered.slice(0, 50).map(trx => `
    <div class="card mb-2" onclick="showTransactionDetail(${trx.id})">
    <div class="card-body p-3">
    <div class="d-flex justify-content-between">
    <div><i class="${trx.category.icon} me-2"></i>${trx.category.name}</div>
    <span class="${trx.type === 'income' ? 'text-success' : 'text-danger'} fw-bold">${trx.formatted_amount}</span>
    </div>
    <small class="text-muted">${trx.wallet.name} · ${formatDate(trx.transaction_date)}</small>
    </div>
    </div>
    `).join('');
    }

    function applyTransactionFilter() {
    state.filters.wallet_id = document.getElementById('filter-wallet')?.value || '';
    updateTransactionStats();
    renderTransactionList();
    }

    function showTransactionDetail(id) {
    const trx = state.allTransactions.find(t => t.id === id);
    if (!trx) return;
    alert(`${trx.category.name}\n${trx.formatted_amount}\n${trx.wallet.name}\n${trx.description || ''}`);
    }

    // ==================== WALLETS PAGE ====================
    function renderWalletsPage() {
    const html = `
    <div class="container py-3">
    <div class="d-flex justify-content-between mb-3">
    <h5>Dompet Saya</h5>
    <button class="btn btn-sm btn-primary" onclick="showAddWalletModal()"><i class="bi bi-plus"></i></button>
    </div>
    <div id="wallet-list"></div>
    </div>
    `;
    document.getElementById('main-content').innerHTML = html;
    renderWalletsList();
    }

    function renderWalletsList() {
    const container = document.getElementById('wallet-list');
    if (!container) return;
    container.innerHTML = state.wallets.map(w => `
    <div class="card mb-2" onclick="editWallet(${w.id})">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div><i class="bi bi-wallet2 me-2"></i>${w.name}</div>
    <strong>${w.formatted_balance}</strong>
    </div>
    <small class="text-muted">${w.description || ''}</small>
    </div>
    </div>
    `).join('');
    }

    // ==================== REPORTS PAGE ====================
    function renderReportsPage() {
    const html = `
    <div class="container py-3">
    <h5>Laporan Keuangan</h5>
    <div class="mb-3">
    <select class="form-select form-select-sm" id="report-wallet" onchange="loadReportCharts()">
    <option value="">Semua Dompet</option>
    ${state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
    </select>
    </div>
    <ul class="nav nav-tabs mb-2" id="reportTab">
    <li class="nav-item"><button class="nav-link active" data-period="monthly" onclick="setReportPeriod('monthly')">Bulanan</button></li>
    <li class="nav-item"><button class="nav-link" data-period="yearly" onclick="setReportPeriod('yearly')">Tahunan</button></li>
    </ul>
    <div style="height: 250px;"><canvas id="reportBarChart"></canvas></div>
    <div id="trend-summary" class="mt-3"></div>
    </div>
    `;
    document.getElementById('main-content').innerHTML = html;
    setTimeout(loadReportCharts, 50);
    }

    function setReportPeriod(period) {
    document.querySelectorAll('#reportTab .nav-link').forEach(btn => btn.classList.remove('active'));
    document.querySelector(`#reportTab [data-period="${period}"]`).classList.add('active');
    loadReportCharts();
    }

    async function loadReportCharts() {
    try {
    const period = document.querySelector('#reportTab .active')?.dataset.period || 'monthly';
    const walletId = document.getElementById('report-wallet')?.value || '';
    let url = `/api/fintech/reports/${period}`;
    if (walletId) url += `?wallet_id=${walletId}`;

    const res = await tgApp.fetchWithAuth(BASE_URL + url);
    const data = res.data;
    const ctx = document.getElementById('reportBarChart')?.getContext('2d');
    if (ctx) {
    if (state.chartInstances.report) state.chartInstances.report.destroy();
    state.chartInstances.report = new Chart(ctx, {
    type: 'bar',
    data: { labels: data.labels, datasets: [
    { label: 'Pemasukan', data: data.income, backgroundColor: '#4DB6AC' },
    { label: 'Pengeluaran', data: data.expense, backgroundColor: '#FF6384' }
    ]}
    });
    }
    } catch(error) {
    alert(error.message);
    }
    }

    // ==================== TRASH PAGE ====================
    async function renderTrashPage() {
    const html = `
    <div class="container py-3">
    <div class="d-flex align-items-center mb-3">
    <button class="btn btn-link me-2" onclick="navigateTo('transactions')"><i class="bi bi-arrow-left"></i></button>
    <h5 class="mb-0">Tempat Sampah</h5>
    </div>
    <div id="trash-list"></div>
    </div>
    `;
    document.getElementById('main-content').innerHTML = html;
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions/trashed');
    const trashed = res.data.data || [];
    const container = document.getElementById('trash-list');
    container.innerHTML = trashed.length ? trashed.map(t => `
    <div class="card mb-2">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div>${t.category.name} · ${t.formatted_amount}</div>
    <div>
    <button class="btn btn-sm btn-outline-success" onclick="restoreTransaction(${t.id})"><i class="bi bi-arrow-counterclockwise"></i></button>
    <button class="btn btn-sm btn-outline-danger" onclick="forceDeleteTransaction(${t.id})"><i class="bi bi-trash"></i></button>
    </div>
    </div>
    </div>
    </div>
    `).join('') : '<p class="text-muted text-center">Kosong</p>';
    }

    async function restoreTransaction(id) {
    await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transactions/${id}/restore`, { method: 'POST' });
    await loadAllTransactions();
    await loadWallets();
    renderTrashPage();
    }

    async function forceDeleteTransaction(id) {
    if (!confirm('Hapus permanen?')) return;
    await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transactions/${id}/force`, { method: 'DELETE' });
    renderTrashPage();
    }

    // ==================== UTILS ====================
    function getCurrencySymbol(code) {
    const c = state.currencies.find(c => c.code === code);
    return c?.symbol || code;
    }
    function formatNumber(n) { return new Intl.NumberFormat('id-ID').format(n); }
    function formatDate(d) { return new Date(d).toLocaleDateString('id-ID', { day:'numeric', month:'short' }); }
    function populateSelectWithCurrencies(select, def) {
    select.innerHTML = state.currencies.map(c => `<option value="${c.code}" ${c.code===def?'selected':''}>${c.name} (${c.symbol})</option>`).join('');
    }
    </script>
    @endpush