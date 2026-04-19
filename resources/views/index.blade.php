@extends('telegram::layouts.mini-app')

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

{{-- Modal Tambah Transaksi --}}
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
            <label class="form-label">Dompet <span class="text-danger">*</span></label>
            <select class="form-select" name="wallet_id" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe <span class="text-danger">*</span></label>
            <select class="form-select" name="type" required>
              <option value="income">Pemasukan</option>
              <option value="expense">Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori <span class="text-danger">*</span></label>
            <select class="form-select" name="category_id" required>
              <option value="">Pilih Kategori</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
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

{{-- Modal Tambah/Edit Dompet --}}
<div class="modal fade" id="walletModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="walletModalTitle">Tambah Dompet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="walletForm">
          <input type="hidden" name="id" id="wallet-id">
          <div class="mb-3">
            <label class="form-label">Nama Dompet <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" id="wallet-name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Mata Uang <span class="text-danger">*</span></label>
            <select class="form-select" name="currency" id="wallet-currency" required>
              <option value="">Memuat data...</option>
            </select>
            <small class="text-muted" id="currency-hint">Mata uang tidak dapat diubah setelah dompet dibuat.</small>
          </div>
          <div class="mb-3" id="initial-balance-group">
            <label class="form-label">Saldo Awal</label>
            <input type="number" class="form-control" name="initial_balance" step="0.01" min="0" value="0">
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi (Opsional)</label>
            <input type="text" class="form-control" name="description" id="wallet-description">
          </div>
          <div class="mb-3" id="is-active-group" style="display: none;">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_active" id="wallet-is-active" value="1" checked>
              <label class="form-check-label" for="wallet-is-active">Dompet Aktif</label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="saveWallet()">Simpan</button>
      </div>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const BASE_URL = '{{ rtrim(config("app.url"), "/") }}';

  // ======================== STATE MANAGEMENT ========================
  const state = {
    wallets: [],
    categories: [],
    currencies: [],
    recentTransactions: [],
    totalBalance: 0,
    currentWalletId: null,
    defaultCurrency: 'IDR',
    activeTab: 'overview',
    fullTransactionPage: 1,
    chartInstance: null
  };

  // ======================== INITIALIZATION ========================
  document.addEventListener('DOMContentLoaded', async () => {
  await initializeApp();
  });

  async function initializeApp() {
    try {
      tgApp.showLoading('Memuat data...');
      await Promise.all([
      loadWallets(),
      loadCategories(),
      loadCurrencies(),
      ]);
      renderApp();
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
      state.totalBalance = state.wallets.reduce((sum, w) => sum + w.balance, 0);
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

  async function loadCurrencies() {
    try {
      const response = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/currencies');
      state.currencies = response.data || [];
    } catch (error) {
      console.error('Gagal load currencies:', error);
      state.currencies = [{
        code: 'IDR',
        name: 'Indonesian Rupiah',
        symbol: 'Rp'
      }];
      tgApp.showToast('Gagal memuat daftar mata uang, menggunakan default', 'warning');
    }
  }

  async function loadRecentTransactions() {
    try {
      const response = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions?per_page=5');
      state.recentTransactions = response.data.data || [];
    } catch (error) {
      console.error('Gagal load recent transactions:', error);
      state.recentTransactions = [];
    }
  }

  async function loadFullTransactions(page = 1) {
    try {
      let url = BASE_URL + `/api/fintech/transactions?page=${page}&per_page=10`;
      if (state.currentWalletId) url += `&wallet_id=${state.currentWalletId}`;
      const response = await tgApp.fetchWithAuth(url);
      const data = response.data;
      renderTransactionListToContainer(data.data, document.getElementById('full-transaction-list'));
      tgApp.renderPagination('transaction-pagination', data.current_page, data.last_page, (newPage) => {
      state.fullTransactionPage = newPage;
      loadFullTransactions(newPage);
      });
    } catch (error) {
      console.error('Gagal load full transactions:', error);
    }
  }

  // ======================== RENDER UTAMA ========================
  function renderApp() {
    const container = document.getElementById('main-content');
    if (state.wallets.length === 0) {
      container.innerHTML = renderEmptyState();
      const form = document.getElementById('firstWalletForm');
      if (form) form.addEventListener('submit', handleCreateFirstWallet);
      const currencySelect = document.getElementById('first-wallet-currency');
      if (currencySelect) populateSelectWithCurrencies(currencySelect, 'IDR');
    } else {
      container.innerHTML = renderDashboard();
      setupWalletSelector();
      renderWalletList();
      renderRecentTransactions();
      updateTotalBalanceDisplay();
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
    <label class="form-label">Mata Uang <span class="text-danger">*</span></label>
    <select class="form-select" name="currency" id="first-wallet-currency" required>
    <option value="">Memuat data...</option>
    </select>
    </div>
    <div class="mb-3">
    <label class="form-label">Saldo Awal</label>
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
    const primaryCurrency = state.wallets[0]?.currency || 'IDR';
    const currencySymbol = getCurrencySymbol(primaryCurrency);
    return `
    <div class="card bg-gradient-primary text-white mb-4" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <div class="card-body">
    <h6 class="card-subtitle mb-2 opacity-75">Total Saldo</h6>
    <h2 class="display-6 fw-bold" id="total-balance-display">${currencySymbol} ${formatNumber(state.totalBalance)}</h2>
    <small>Semua Dompet Aktif</small>
    </div>
    </div>
    <div class="mb-3">
    <label class="form-label fw-semibold">Dompet Aktif</label>
    <select class="form-select" id="wallet-selector">
    <option value="">Semua Dompet</option>
    </select>
    </div>
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
    <div class="tab-content" id="mainTabContent">
    <div class="tab-pane fade show active" id="overview">
    <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Pengeluaran Minggu Ini</h5>
    <button class="btn btn-sm btn-outline-primary" onclick="loadDoughnutChart()">
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

    // ======================== UI HELPERS ========================
    function setupWalletSelector() {
    const selector = document.getElementById('wallet-selector');
    if (!selector) return;
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
    loadDoughnutChart();
    updateTotalBalanceDisplay();
    });
    }

    function updateTotalBalanceDisplay() {
    const displayEl = document.getElementById('total-balance-display');
    if (!displayEl) return;
    let total = 0;
    let currency = state.wallets[0]?.currency || 'IDR';
    if (state.currentWalletId) {
    const wallet = state.wallets.find(w => w.id == state.currentWalletId);
    if (wallet) {
    total = wallet.balance;
    currency = wallet.currency;
    }
    } else {
    total = state.totalBalance;
    }
    const symbol = getCurrencySymbol(currency);
    displayEl.textContent = `${symbol} ${formatNumber(total)}`;
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
    renderTransactionListToContainer(state.recentTransactions, container);
    }

    function renderTransactionListToContainer(transactions, container) {
    if (!transactions.length) {
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
    <div class="small text-muted">${formatDate(trx.transaction_date)} · ${trx.wallet.name}</div>
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

    async function refreshTransactionData() {
    await loadRecentTransactions();
    renderRecentTransactions();
    if (document.getElementById('full-transaction-list')) {
    await loadFullTransactions(state.fullTransactionPage);
    }
    }

    // ======================== CHART ========================
    async function loadDoughnutChart() {
    try {
    let url = BASE_URL + '/api/fintech/reports/doughnut-weekly';
    if (state.currentWalletId) url += `?wallet_id=${state.currentWalletId}`;
    const response = await tgApp.fetchWithAuth(url);
    const chartData = response.data;
    const ctx = document.getElementById('doughnutChart')?.getContext('2d');
    if (!ctx) return;
    if (state.chartInstance) state.chartInstance.destroy();
    if (!chartData.labels.length) return;
    state.chartInstance = new Chart(ctx, {
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
    legend: { position: 'bottom', labels: { boxWidth: 12 } },
    tooltip: {
    callbacks: {
    label: (context) => {
    const value = context.raw;
    const total = context.dataset.data.reduce((a, b) => a + b, 0);
    const percentage = ((value / total) * 100).toFixed(1);
    return `${context.label}: ${formatNumber(value)} (${percentage}%)`;
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

    // ======================== CURRENCY HELPERS ========================
    function populateSelectWithCurrencies(selectElement, defaultCode = 'IDR') {
    if (!selectElement) return;
    selectElement.innerHTML = '<option value="">Pilih Mata Uang</option>';
    state.currencies.forEach(curr => {
    const option = document.createElement('option');
    option.value = curr.code;
    option.textContent = `${curr.name} (${curr.symbol || curr.code})`;
    if (curr.code === defaultCode) option.selected = true;
    selectElement.appendChild(option);
    });
    }

    function getCurrencySymbol(code) {
    const currency = state.currencies.find(c => c.code === code);
    return currency?.symbol || code;
    }

    // ======================== FORM HANDLERS ========================
    function showAddWalletModal(wallet = null) {
    const form = document.getElementById('walletForm');
    form.reset();
    const isEdit = !!wallet;
    document.getElementById('walletModalTitle').textContent = isEdit ? 'Edit Dompet' : 'Tambah Dompet';
    document.getElementById('wallet-id').value = wallet?.id || '';
    document.getElementById('wallet-name').value = wallet?.name || '';
    document.getElementById('wallet-description').value = wallet?.description || '';

    const currencySelect = document.getElementById('wallet-currency');
    populateSelectWithCurrencies(currencySelect, wallet?.currency || 'IDR');
    currencySelect.disabled = isEdit;
    document.getElementById('currency-hint').style.display = isEdit ? 'block' : 'none';

    document.getElementById('initial-balance-group').style.display = isEdit ? 'none' : 'block';
    document.getElementById('is-active-group').style.display = isEdit ? 'block' : 'none';
    if (isEdit) {
    document.getElementById('wallet-is-active').checked = wallet.is_active;
    }
    new bootstrap.Modal(document.getElementById('walletModal')).show();
    }

    async function saveWallet() {
    const form = document.getElementById('walletForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    const isEdit = !!id;

    // Hapus field yang tidak diperlukan
    if (isEdit) {
    delete data.initial_balance;
    delete data.currency;
    data.is_active = data.is_active === '1';
    } else {
    delete data.id;
    delete data.is_active;
    }

    // Validasi client-side nama duplikat (kecuali edit dengan nama sama)
    if (!isEdit && state.wallets.some(w => w.name.toLowerCase() === data.name.toLowerCase())) {
    tgApp.showToast('Nama dompet sudah digunakan', 'warning');
    return;
    }

    try {
    tgApp.showLoading('Menyimpan...');
    const url = isEdit ? `${BASE_URL}/api/fintech/wallets/${id}` : `${BASE_URL}/api/fintech/wallets`;
    const method = isEdit ? 'PUT' : 'POST';
    await tgApp.fetchWithAuth(url, { method, body: JSON.stringify(data) });

    await loadWallets();
    tgApp.hideLoading();
    tgApp.showToast(isEdit ? 'Dompet berhasil diperbarui' : 'Dompet berhasil dibuat');
    bootstrap.Modal.getInstance(document.getElementById('walletModal')).hide();
    renderApp();
    } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menyimpan dompet', 'danger');
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
    await loadWallets();
    tgApp.hideLoading();
    tgApp.showToast('Dompet berhasil dibuat!');
    renderApp();
    } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal membuat dompet', 'danger');
    }
    }

    function showAddTransactionModal() {
    const form = document.getElementById('transactionForm');
    form.reset();
    document.querySelector('input[name="transaction_date"]').value = new Date().toISOString().split('T')[0];

    const walletSelect = document.querySelector('select[name="wallet_id"]');
    walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    walletSelect.appendChild(option);
    });

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
    await loadWallets();
    await loadRecentTransactions();
    tgApp.hideLoading();
    tgApp.showToast('Transaksi berhasil disimpan');
    bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();

    updateTotalBalanceDisplay();
    setupWalletSelector();
    renderRecentTransactions();
    loadDoughnutChart();
    if (document.getElementById('full-transaction-list')) {
    await loadFullTransactions(state.fullTransactionPage);
    }
    } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menyimpan transaksi', 'danger');
    }
    }

    // ======================== UTILITY ========================
    function formatNumber(num) {
    return new Intl.NumberFormat('id-ID').format(num);
    }

    function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    // ======================== EVENT LISTENERS ========================
    document.addEventListener('shown.bs.tab', (e) => {
    if (e.target.id === 'transactions-tab') {
    loadFullTransactions(state.fullTransactionPage);
    }
    });
    </script>
    @endpush