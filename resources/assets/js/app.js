// ==================== STATE MANAGEMENT ====================
const state = {
  wallets: [],
  categories: [],
  currencies: [],
  homeSummary: null,
  transactions: [],
  transactionPage: 1,
  transactionLastPage: 1,
  transactionSummary: {
    total: 0,
    income: 0,
    expense: 0
  },
  transfers: [],
  transferPage: 1,
  transferLastPage: 1,
  totalBalance: 0,
  currentPage: 'home',
  filters: {
    wallet_id: '',
    type: '',
    month: ''
  },
  chartInstances: {},
  userSettings: null,
  reportFilter: {
    wallet_id: '',
    periodType: 'all_years',
    date: null,
    month: null,
    year: null
  },
  statementPage: 1,
  statementLastPage: 1,
  statements: [],
};

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', async () => {
  await initializeApp();
  setupNavigation();
  setupFabOpacity();
});

async function initializeApp() {
  const loadingOverlay = document.getElementById('loading-overlay');
  try {
    loadingOverlay.classList.remove('d-none');
    loadingOverlay.innerHTML = `
    <div class="text-center">
    <div class="spinner-border text-primary mb-3" role="status"></div>
    <p class="text-muted">Memuat data keuangan...</p>
    </div>
    `;

    await Promise.all([
      loadWallets().catch(e => {
        throw new Error('Gagal memuat dompet: ' + e.message);
      }),
      loadUserSettings().catch(e => console.warn(e)),
      loadCategories().catch(e => {
        throw new Error('Gagal memuat kategori: ' + e.message);
      }),
      loadCurrencies().catch(e => {
        throw new Error('Gagal memuat mata uang: ' + e.message);
      })
    ]);

    if (state.wallets.length > 0) {
      await loadHomeSummary().catch(e => tgApp.showToast('Gagal memuat ringkasan', 'warning'));
    }

    navigateTo('home');
    loadingOverlay.classList.add('d-none');
  } catch (error) {
    console.error('Init error:', error);
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
    loadingOverlay.classList.remove('d-none');
  }
}

function retryInitialization() {
  initializeApp();
}

function setupNavigation() {
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', () => navigateTo(btn.dataset.page));
  });
}

function setupFabOpacity() {
  const fab = document.getElementById('fab-button');
  if (!fab) return;
  fab.style.opacity = '0.7';
  fab.addEventListener('shown.bs.dropdown', () => fab.style.opacity = '1');
  fab.addEventListener('hidden.bs.dropdown', () => fab.style.opacity = '0.7');
}

// ==================== DATA LOADING ====================
async function loadWallets() {
  const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/wallets');
  state.wallets = res.data || [];
  state.totalBalance = state.wallets.reduce((s, w) => s + w.balance, 0);
}

async function loadCategories() {
  const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/categories');
  state.categories = res.data || [];
}

async function loadCurrencies() {
  const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/currencies');
  state.currencies = res.data || [];
}

async function loadHomeSummary() {
  const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/home-summary');
  state.homeSummary = res.data;
}

async function loadTransactionsPage(page, filters) {
  let url = `${BASE_URL}/api/fintech/transactions?per_page=20&page=${page}`;
  if (filters.wallet_id) url += `&wallet_id=${filters.wallet_id}`;
  if (filters.type) url += `&type=${filters.type}`;
  const res = await tgApp.fetchWithAuth(url);
  state.transactions = res.data.data;
  state.transactionPage = res.data.current_page;
  state.transactionLastPage = res.data.last_page;
  state.transactionSummary = res.summary;
}

async function loadTransfersPage(page, walletId) {
  try {
    let url = `${BASE_URL}/api/fintech/transfers?per_page=20&page=${page}`;
    if (walletId) url += `&wallet_id=${walletId}`;
    const res = await tgApp.fetchWithAuth(url);
    const data = res.data;
    state.transfers = data.data;
    state.transferPage = data.current_page;
    state.transferLastPage = data.last_page;
  } catch (error) {
    tgApp.showToast("Gagal memuat data transfer. " + error.message);
  }
}

async function loadUserSettings() {
  try {
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/settings');
    state.userSettings = res.data;
  } catch (error) {
    console.warn('Gagal memuat pengaturan:', error);
    state.userSettings = {
      default_currency: 'IDR',
      default_wallet_id: null
    };
  }
}

async function loadStatements(page = 1) {
  try {
    const res = await tgApp.fetchWithAuth(BASE_URL + `/api/fintech/statements?page=${page}`);
    state.statements = res.data.data;
    state.statementPage = res.data.current_page;
    state.statementLastPage = res.data.last_page;
  } catch (error) {
    tgApp.showToast('Gagal memuat statement', 'danger');
  }
}

// ==================== NAVIGATION ====================
function navigateTo(page) {
  state.currentPage = page;
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.page === page);
  });
  document.querySelectorAll('.dropdown-item.nav-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.page === page);
  });

  const container = document.getElementById('main-content');
  if (state.wallets.length === 0) {
    container.innerHTML = renderEmptyState();
    return;
  }

  const pages = {
    home: renderHomePage,
    transactions: renderTransactionsPage,
    transfers: renderTransfersPage,
    wallets: renderWalletsPage,
    reports: renderReportsPage,
    settings: renderSettingsPage,
    insights: renderInsightsPage,
    statements: renderStatementsPage,
  };
  if (pages[page]) pages[page]();
}

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

// ==================== GENERIC LIST PAGE ====================
async function renderListPage(config) {
  const {
    title,
    icon,
    filterHtml,
    listContainerId,
    paginationId,
    loadFn,
    extraHeaderButtons = ''
  } = config;
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between mb-3">
  <div class="d-flex"><i class="${icon} me-2"></i><h5>${title}</h5></div>
  <div>${extraHeaderButtons}</div>
  </div>
  ${filterHtml || ''}
  <div id="${listContainerId}"></div>
  <div id="${paginationId}" class="mt-3"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  await loadFn(1);
}

function renderPagination(containerId, page, lastPage, onPageChange) {
  if (lastPage <= 1) {
    document.getElementById(containerId).innerHTML = '';
    return;
  }
  tgApp.renderPagination(containerId, page, lastPage, async (newPage) => {
    await onPageChange(newPage);
  });
}

// ==================== HOME PAGE ====================
function renderHomePage() {
  const summary = state.homeSummary;
  if (!summary) {
    document.getElementById('main-content').innerHTML = '<p class="text-center py-5">Memuat ringkasan...</p>';
    return;
  }

  const symbol = getCurrencySymbol(summary.currency);
  const html = `
  <div class="container py-3">
  <div class="card bg-gradient-primary text-white mb-3" style="background: linear-gradient(135deg, #667eea, #764ba2);">
  <div class="card-body">
  <h6>Total Saldo</h6>
  <h2>${symbol} ${formatNumber(summary.total_balance)}</h2>
  <small>${state.wallets.length} dompet aktif</small>
  </div>
  </div>
  <div class="row g-2 mb-3">
  <div class="col-6">
  <div class="card"><div class="card-body p-3 text-center"><i class="bi bi-arrow-down-circle text-success fs-4"></i><h6 class="mb-0">${formatNumberShort(summary.total_income)}</h6><small>Pemasukan</small></div></div>
  </div>
  <div class="col-6">
  <div class="card"><div class="card-body p-3 text-center"><i class="bi bi-arrow-up-circle text-danger fs-4"></i><h6 class="mb-0">${formatNumberShort(summary.total_expense)}</h6><small>Pengeluaran</small></div></div>
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
  setTimeout(() => {
    loadHomeChartFromSummary();
    renderRecentTransactionsFromSummary();
  }, 50);
}

function loadHomeChartFromSummary() {
  const ctx = document.getElementById('homeChart')?.getContext('2d');
  if (!ctx) return;
  const data = state.homeSummary.weekly_expense;
  if (state.chartInstances.home) state.chartInstances.home.destroy();
  if (!data || data.length === 0) return;
  state.chartInstances.home = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: data.map(d => d.label), datasets: [{
        data: data.map(d => d.value), backgroundColor: data.map(d => d.color)
      }]
    }
  });
}

function renderRecentTransactionsFromSummary() {
  const container = document.getElementById('recent-transactions');
  const transactions = state.homeSummary.recent_transactions;
  if (!transactions.length) {
    container.innerHTML = '<p class="text-muted text-center">Belum ada transaksi</p>';
    return;
  }
  container.innerHTML = transactions.map(trx => {
    const amountClass = trx.type === 'income' ? 'text-success': 'text-danger';
    const sign = trx.type === 'income' ? '': '-';
    return `<div class="d-flex justify-content-between align-items-center border-bottom py-2"><div><i class="${trx.category.icon} me-2" style="color:${trx.category.color}"></i>${trx.category.name}</div><span class="${amountClass}" title="${trx.formatted_amount}">${sign}${formatNumberShort(trx.amount)}</span></div>`;
  }).join('');
}

// ==================== TRANSACTIONS PAGE ====================
async function renderTransactionsPage() {
  await renderListPage( {
    title: 'Transaksi',
    icon: 'bi bi-list-ul',
    filterHtml: `
    <div class="row g-2 mb-3" id="transaction-stats"></div>
    <div class="mb-3">
    <select class="form-select form-select-sm" id="filter-wallet" onchange="applyTransactionFilter()">
    <option value="">Semua Dompet</option>
    ${state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
    </select>
    </div>
    `,
    listContainerId: 'transaction-list',
    paginationId: 'transaction-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-secondary me-1" onclick="navigateToTrash()"><i class="bi bi-trash"></i></button>
    <button class="btn btn-sm btn-primary" onclick="showAddTransactionModal()"><i class="bi bi-plus"></i></button>
    `,
    loadFn: refreshTransactionList
  });
  updateTransactionStats();
}

async function refreshTransactionList(page = 1) {
  const filters = {
    wallet_id: state.filters.wallet_id,
    type: state.filters.type
  };
  await loadTransactionsPage(page, filters);
  updateTransactionStats();
  renderTransactionList();
  renderPagination('transaction-pagination', state.transactionPage, state.transactionLastPage, refreshTransactionList);
}

function updateTransactionStats() {
  const summary = state.transactionSummary;
  const symbol = getCurrencySymbol(state.userSettings?.default_currency || 'IDR');
  document.getElementById('transaction-stats').innerHTML = `
  <div class="col-4"><div class="card p-2 text-center"><small>Total</small><strong>${summary.total}</strong></div></div>
  <div class="col-4"><div class="card p-2 text-center text-success"><small>Masuk</small><strong>${symbol}${formatNumberShort(summary.income)}</strong></div></div>
  <div class="col-4"><div class="card p-2 text-center text-danger"><small>Keluar</small><strong>${symbol}${formatNumberShort(summary.expense)}</strong></div></div>
  `;
}

function renderTransactionList() {
  const filtered = state.transactions;
  const container = document.getElementById('transaction-list');
  if (filtered.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Tidak ada transaksi</p>';
    return;
  }
  container.innerHTML = filtered.map(trx => {
    const amountClass = trx.type === 'income' ? 'text-success': 'text-danger';
    const sign = trx.type === 'income' ? '': '-';
    return `
    <div class="card mb-2"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1" onclick="showTransactionDetailModal(${trx.id})" style="cursor: pointer;">
    <div class="d-flex align-items-center">
    <i class="${trx.category.icon} me-2" style="color:${trx.category.color}"></i>
    <div><div class="fw-semibold">${trx.category.name}</div><small class="text-muted">${trx.wallet.name} · ${formatDate(trx.transaction_date)}</small></div>
    </div>
    ${trx.description ? `<small class="text-muted d-block mt-1">${trx.description}</small>`: ''}
    </div>
    <div class="d-flex align-items-center">
    <span class="${amountClass} fw-bold me-2" title="${trx.formatted_amount}">${sign}${formatNumberShort(trx.amount)}</span>
    <div class="dropdown" onclick="event.stopPropagation()">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="#" onclick="editTransaction(${trx.id})"><i class="bi bi-pencil me-2"></i>Edit</a></li>
    <li><a class="dropdown-item text-danger" href="#" onclick="deleteTransaction(${trx.id})"><i class="bi bi-trash me-2"></i>Hapus</a></li>
    </ul>
    </div>
    </div>
    </div>
    </div></div>
    `;
  }).join('');
}

function applyTransactionFilter() {
  state.filters.wallet_id = document.getElementById('filter-wallet')?.value || '';
  state.transactionPage = 1;
  refreshTransactionList();
}

function showTransactionDetailModal(id) {
  const trx = state.transactions.find(t => t.id === id);
  if (!trx) return;

  const body = document.getElementById('transactionDetailBody');
  const typeLabel = trx.type === 'income' ? 'Pemasukan': 'Pengeluaran';
  const amountClass = trx.type === 'income' ? 'text-success': 'text-danger';
  const sign = trx.type === 'income' ? '': '-';

  body.innerHTML = `
  <div class="text-center mb-3">
  <i class="${trx.category.icon} fs-1" style="color: ${trx.category.color}"></i>
  <h5 class="mt-2">${trx.category.name}</h5>
  <span class="badge bg-secondary">${typeLabel}</span>
  </div>
  <table class="table table-sm">
  <tr><th>Jumlah</th><td class="${amountClass} fw-bold">${sign}${trx.formatted_amount}</td></tr>
  <tr><th>Dompet</th><td>${trx.wallet.name}</td></tr>
  <tr><th>Tanggal</th><td>${formatDateFull(trx.transaction_date)}</td></tr>
  <tr><th>Deskripsi</th><td>${trx.description || '-'}</td></tr>
  </table>
  `;

  new bootstrap.Modal(document.getElementById('transactionDetailModal')).show();
}

async function deleteTransaction(id) {
  if (!confirm('Pindahkan transaksi ke tempat sampah?')) return;
  try {
    tgApp.showLoading('Menghapus...');
    await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transactions/${id}`, {
      method: 'DELETE'
    });
    await loadWallets();
    await loadHomeSummary();
    tgApp.hideLoading();
    tgApp.showToast('Transaksi dipindahkan ke tempat sampah');
    if (state.currentPage === 'transactions') {
      await refreshTransactionList();
    } else if (state.currentPage === 'home') {
      renderHomePage();
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

// ==================== TRANSFERS PAGE ====================
async function renderTransfersPage() {
  await renderListPage( {
    title: 'Transfer',
    icon: 'bi bi-arrow-left-right',
    filterHtml: `
    <div class="mb-3">
    <select class="form-select form-select-sm" id="transfer-wallet-filter" onchange="applyTransferFilter()">
    <option value="">Semua Dompet</option>
    ${state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
    </select>
    </div>
    `,
    listContainerId: 'transfer-list',
    paginationId: 'transfer-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-secondary me-1" onclick="navigateToTransferTrash()"><i class="bi bi-trash"></i></button>
    <button class="btn btn-sm btn-primary" onclick="showAddTransferModal()"><i class="bi bi-plus"></i></button>
    `,
    loadFn: refreshTransferList
  });
}

async function refreshTransferList(page = 1) {
  const walletId = document.getElementById('transfer-wallet-filter')?.value || '';
  await loadTransfersPage(page, walletId);
  renderTransferList(state.transfers);
  renderPagination('transfer-pagination', state.transferPage, state.transferLastPage, refreshTransferList);
}

function renderTransferList(transfers) {
  const container = document.getElementById('transfer-list');
  if (!transfers.length) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada transfer</p>';
    return;
  }
  container.innerHTML = transfers.map(t => `
    <div class="card mb-2">
    <div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1" onclick="editTransfer(${t.id})">
    <div class="d-flex align-items-center mb-1">
    <i class="bi bi-arrow-right me-2 text-primary"></i>
    <span>${t.from_wallet.name} → ${t.to_wallet.name}</span>
    </div>
    <div class="text-primary fw-bold mb-1" title="${t.formatted_amount}">↔ ${formatNumberShort(t.amount)}</div>
    <small class="text-muted">${formatDate(t.transfer_date)}</small>
    ${t.description ? `<div class="small text-muted mt-1">${t.description}</div>`: ''}
    </div>
    <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown">
    <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="#" onclick="editTransfer(${t.id})">
    <i class="bi bi-pencil me-2"></i>Edit
    </a></li>
    <li><a class="dropdown-item text-danger" href="#" onclick="deleteTransfer(${t.id})">
    <i class="bi bi-trash me-2"></i>Hapus
    </a></li>
    </ul>
    </div>
    </div>
    </div>
    </div>
    `).join('');
}

function applyTransferFilter() {
  state.transferPage = 1; refreshTransferList();
}

async function deleteTransfer(id) {
  if (!confirm('Pindahkan transfer ke tempat sampah?')) return;
  try {
    tgApp.showLoading('Menghapus...');
    await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transfers/${id}`, {
      method: 'DELETE'
    });
    await loadWallets();
    await loadHomeSummary();
    tgApp.hideLoading();
    tgApp.showToast('Transfer dipindahkan ke tempat sampah');
    if (state.currentPage === 'transfers') {
      await refreshTransferList();
    } else if (state.currentPage === 'home') {
      renderHomePage();
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

// ==================== WALLETS PAGE ====================
function renderWalletsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between mb-3">
  <div class="d-flex"><i class="bi bi-wallet2 me-2"></i><h5>Dompet Saya</h5></div>
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
  <div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Laporan Keuangan</h5>
  <button class="btn btn-sm btn-outline-primary" onclick="showReportFilterModal()">
  <i class="bi bi-funnel"></i> Filter
  </button>
  </div>
  <div style="height: 250px;">
  <canvas id="reportBarChart"></canvas>
  </div>
  <div id="trend-summary" class="mt-3"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  setTimeout(loadReportCharts, 50);
}

function showReportFilterModal() {
  // Isi dropdown dompet
  const walletSelect = document.getElementById('filter-wallet');
  if (!walletSelect) return;
  walletSelect.innerHTML = '<option value="">Semua Dompet</option>';
  state.wallets.forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    if (w.id == state.reportFilter.wallet_id) option.selected = true;
    walletSelect.appendChild(option);
  });

  // Set tipe periode
  const periodTypeSelect = document.getElementById('filter-period-type');
  periodTypeSelect.value = state.reportFilter.periodType;

  // Render input detail periode
  renderPeriodDetailInputs();

  // Event saat tipe periode berubah
  periodTypeSelect.onchange = renderPeriodDetailInputs;

  new bootstrap.Modal(document.getElementById('reportFilterModal')).show();
}

function renderPeriodDetailInputs() {
  const type = document.getElementById('filter-period-type').value;
  const container = document.getElementById('filter-period-detail');
  const filter = state.reportFilter;
  const currentYear = new Date().getFullYear();
  const currentMonth = new Date().toISOString().slice(0,
    7);

  if (type === 'all_years') {
    container.innerHTML = `<p class="text-muted small">Menampilkan total per tahun untuk semua tahun yang memiliki data.</p>`;
  } else if (type === 'monthly') {
    container.innerHTML = `
    <label class="form-label">Bulan</label>
    <input type="month" class="form-control" id="filter-month" value="${filter.month || currentMonth}">
    `;
  } else if (type === 'yearly') {
    const yearOptions = [];
    for (let y = currentYear; y >= currentYear - 10; y--) {
      yearOptions.push(`<option value="${y}" ${y == (filter.year || currentYear) ? 'selected': ''}>${y}</option>`);
    }
    container.innerHTML = `
    <label class="form-label">Tahun</label>
    <select class="form-select" id="filter-year">
    ${yearOptions}
    </select>
    `;
  }
}

function applyReportFilter() {
  const walletId = document.getElementById('filter-wallet').value;
  const periodType = document.getElementById('filter-period-type').value;

  state.reportFilter.wallet_id = walletId;
  state.reportFilter.periodType = periodType;

  if (periodType === 'all_years') {
    state.reportFilter.date = null;
    state.reportFilter.month = null;
    state.reportFilter.year = null;
  } else if (periodType === 'monthly') {
    state.reportFilter.month = document.getElementById('filter-month').value;
    state.reportFilter.date = null;
    state.reportFilter.year = null;
  } else if (periodType === 'yearly') {
    state.reportFilter.year = document.getElementById('filter-year').value;
    state.reportFilter.date = null;
    state.reportFilter.month = null;
  }

  bootstrap.Modal.getInstance(document.getElementById('reportFilterModal')).hide();
  loadReportCharts();
}

async function loadReportCharts() {
  try {
    const filter = state.reportFilter;
    let url = `${BASE_URL}/api/fintech/reports/${filter.periodType}`;
    const params = new URLSearchParams();

    if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);

    if (filter.periodType === 'monthly' && filter.month) {
      const [year,
        month] = filter.month.split('-');
      params.append('year', parseInt(year, 10));
      params.append('month', parseInt(month, 10));
    } else if (filter.periodType === 'yearly' && filter.year) {
      params.append('year', parseInt(filter.year, 10));
    }
    // Untuk all_years tidak perlu parameter tambahan

    if (params.toString()) url += '?' + params.toString();

    const res = await tgApp.fetchWithAuth(url);
    const data = res.data;
    const ctx = document.getElementById('reportBarChart')?.getContext('2d');
    if (ctx) {
      if (state.chartInstances.report) state.chartInstances.report.destroy();
      state.chartInstances.report = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.labels,
          datasets: [{
            label: 'Pemasukan', data: data.income, backgroundColor: '#4DB6AC'
          },
            {
              label: 'Pengeluaran', data: data.expense, backgroundColor: '#FF6384'
            }]
        }
      });
    }

    // Ringkasan
    const summaryEl = document.getElementById('trend-summary');
    if (summaryEl) {
      const totalIncome = data.income.reduce((a, b) => a + b, 0);
      const totalExpense = data.expense.reduce((a, b) => a + b, 0);
      summaryEl.innerHTML = `
      <div class="row">
      <div class="col-6">
      <div class="card text-center p-2">
      <small class="text-success">Pemasukan</small>
      <strong>${formatNumber(totalIncome)}</strong>
      </div>
      </div>
      <div class="col-6">
      <div class="card text-center p-2">
      <small class="text-danger">Pengeluaran</small>
      <strong>${formatNumber(totalExpense)}</strong>
      </div>
      </div>
      </div>
      `;
    }
  } catch (error) {
    tgApp.showToast('Gagal memuat laporan. ' + error.message, 'danger');
  }
}

// ==================== SETTINGS PAGE ====================
function renderSettingsPage() {
  const settings = state.userSettings || {
    default_currency: 'IDR',
    default_wallet_id: ''
  };
  const symbol = getCurrencySymbol(settings.default_currency);
  const html = `
  <div class="container py-3">
  <div class="d-flex align-items-center mb-3">
  <i class="bi bi-gear me-2"></i>
  <h5 class="mb-0">Pengaturan</h5>
  </div>
  <form id="settingsForm">
  <div class="mb-3">
  <label class="form-label">Mata Uang Default</label>
  <select class="form-select" name="default_currency" id="setting-currency">
  <option value="">Pilih Mata Uang</option>
  </select>
  <small class="text-muted">Digunakan sebagai default saat membuat dompet baru.</small>
  </div>
  <div class="mb-3">
  <label class="form-label">Dompet Default</label>
  <select class="form-select" name="default_wallet_id" id="setting-wallet">
  <option value="">Tidak Ada</option>
  ${state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
  </select>
  <small class="text-muted">Dompet yang otomatis terpilih saat menambah transaksi.</small>
  </div>
  <button type="button" class="btn btn-primary w-100" onclick="saveSettings()">
  Simpan Pengaturan
  </button>
  </form>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;

  // Isi dropdown mata uang
  const currencySelect = document.getElementById('setting-currency');
  populateSelectWithCurrencies(currencySelect,
    settings.default_currency);

  // Isi dropdown dompet
  const walletSelect = document.getElementById('setting-wallet');
  if (settings.default_wallet_id) {
    walletSelect.value = settings.default_wallet_id;
  }
}
async function saveSettings() {
  const form = document.getElementById('settingsForm');
  const formData = new FormData(form);
  const data = Object.fromEntries(formData.entries());

  try {
    tgApp.showLoading('Menyimpan...');
    await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/settings', {
      method: 'PUT',
      body: JSON.stringify(data)
    });
    await loadUserSettings();
    tgApp.hideLoading();
    tgApp.showToast('Pengaturan disimpan');
    navigateTo('home');
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
  }
}

// ==================== INSIGHTS PAGE ====================
async function renderInsightsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
  <div class="d-flex">
  <i class="bi bi-bar-chart me-2"></i>
  <h5>Analisis Keuangan</h5>
  </div>
  </div>
  <div id="insights-content">
  <div class="text-center py-5">
  <div class="spinner-border text-primary" role="status"></div>
  <p class="mt-2">Menganalisis data...</p>
  </div>
  </div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  await loadInsights();
}
async function loadInsights() {
  try {
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/insights/full');
    renderInsightsContent(res.data);
  } catch (error) {
    document.getElementById('insights-content').innerHTML = `
    <div class="alert alert-danger">Gagal memuat analisis</div>
    `;
  }
}
function renderInsightsContent(data) {
  const symbol = getCurrencySymbol(data.currency || 'IDR');
  const trend = data.trend;
  const changeClass = trend.change_percentage > 0 ? 'text-danger': 'text-success';
  const changeIcon = trend.change_percentage > 0 ? '↑': '↓';

  let html = `
  <div class="container py-3">
  <!-- Summary Card -->
  <div class="card mb-3">
  <div class="card-body">
  <h6>Total Pengeluaran Bulan Ini</h6>
  <h3>${symbol} ${formatNumber(trend.current_month_total)}</h3>
  <p class="${changeClass} mb-0">
  ${changeIcon} ${Math.abs(trend.change_percentage)}% dari bulan lalu
  </p>
  <small class="text-muted">Rata-rata 3 bulan: ${symbol} ${formatNumber(trend.avg_last_3months)}</small>
  </div>
  </div>

  <!-- Recommendations -->
  ${data.recommendations.length > 0 ? `
  <div class="card mb-3 border-0 shadow-sm">
  <div class="card-header bg-white border-0 pt-3">
  <h6 class="mb-0"><i class="bi bi-lightbulb text-warning me-2"></i>Rekomendasi Cerdas</h6>
  </div>
  <div class="card-body pt-0">
  ${data.recommendations.map(rec => `
    <div class="d-flex alert alert-${rec.type === 'warning' ? 'warning': (rec.type === 'success' ? 'success': 'info')} py-2 px-3 mb-2">
    <i class="bi ${rec.icon} me-3 fs-5"></i>
    <div>
    <strong>${rec.title}</strong><br>
    <small>${rec.message}</small>
    </div>
    </div>
    `).join('')}
  </div>
  </div>
  `: ''}

  <!-- Anomalies -->
  ${data.anomalies.length > 0 ? `
  <div class="card mb-3">
  <div class="card-header">⚠️ Lonjakan Pengeluaran</div>
  <div class="list-group list-group-flush">
  ${data.anomalies.map(a => `
    <div class="list-group-item">
    <div class="d-flex align-items-center">
    <i class="${a.category.icon} me-2" style="color:${a.category.color}"></i>
    <span class="flex-grow-1">${a.category.name}</span>
    <span class="text-danger">${a.formatted} (+${a.percentage_increase}%)</span>
    </div>
    </div>
    `).join('')}
  </div>
  </div>
  `: ''}

  <!-- Subscriptions -->
  ${data.subscriptions.length > 0 ? `
  <div class="card mb-3">
  <div class="card-header">📅 Langganan Bulanan</div>
  <div class="list-group list-group-flush">
  ${data.subscriptions.map(s => `
    <div class="list-group-item">
    <div>${s.category.name} · ${s.description || '-'}</div>
    <small class="text-muted">${s.formatted} / bulan · ${s.occurrences}x berturut-turut</small>
    </div>
    `).join('')}
  </div>
  </div>
  `: ''}

  <!-- Spending Ratio -->
  <div class="card mb-3">
  <div class="card-header">🥧 Komposisi Pengeluaran</div>
  <div class="card-body">
  <div class="progress mb-2" style="height: 20px;">
  <div class="progress-bar bg-success" style="width: ${data.spending_ratio.primary}%">Pokok ${data.spending_ratio.primary}%</div>
  <div class="progress-bar bg-warning" style="width: ${data.spending_ratio.secondary}%">Sekunder ${data.spending_ratio.secondary}%</div>
  <div class="progress-bar bg-danger" style="width: ${data.spending_ratio.tertiary}%">Tersier ${data.spending_ratio.tertiary}%</div>
  </div>
  </div>
  </div>

  <!-- Projection -->
  <div class="card mb-3">
  <div class="card-header">📈 Proyeksi Bulan Depan</div>
  <div class="card-body">
  <p>Estimasi Pemasukan: <strong class="text-success">${data.projection.formatted_income}</strong></p>
  <p>Estimasi Pengeluaran: <strong class="text-danger">${data.projection.formatted_expense}</strong></p>
  <p>Surplus/Defisit: <strong class="${data.projection.projected_surplus >= 0 ? 'text-success': 'text-danger'}">
  ${symbol} ${formatNumber(data.projection.projected_surplus)}
  </strong></p>
  </div>
  </div>

  <!-- Top Categories -->
  <div class="card mb-3">
  <div class="card-header">🏆 Top Kategori Bulan Ini</div>
  <div class="list-group list-group-flush">
  ${data.top_categories.map((cat, i) => `
    <div class="list-group-item">
    <div class="d-flex align-items-center">
    <span class="badge bg-secondary me-2">#${i+1}</span>
    <i class="${cat.icon} me-2" style="color:${cat.color}"></i>
    <span class="flex-grow-1">${cat.name}</span>
    <strong>${cat.formatted}</strong>
    </div>
    </div>
    `).join('')}
  </div>
  </div>
  </div>
  `;
  document.getElementById('insights-content').innerHTML = html;
}
function generateRecommendations(data) {
  const recs = [];
  if (data.percentage_change > 20) {
    recs.push('<li class="mb-2"><i class="bi bi-exclamation-triangle text-warning me-2"></i> Pengeluaran naik signifikan. Coba review transaksi minggu ini.</li>');
  }
  if (data.top_categories[0]?.name?.includes('Makanan')) {
    recs.push('<li class="mb-2"><i class="bi bi-cup me-2"></i> Pengeluaran makan cukup besar. Coba bawa bekal 2x seminggu.</li>');
  }
  if (recs.length === 0) {
    recs.push('<li class="text-muted">Pengeluaran Anda terkendali. Pertahankan!</li>');
  }
  return recs.join('');
}

// ==================== STATEMENTS PAGE ====================
async function renderStatementsPage() {
  await renderListPage( {
    title: 'Riwayat Statement',
    icon: 'bi-file-text',
    listContainerId: 'statement-list',
    paginationId: 'statement-pagination',
    loadFn: refreshStatementList,
    extraHeaderButtons: '<button class="btn btn-sm btn-outline-primary" onclick="showUploadStatementModal();"><i class="bi bi-cloud-upload"></i></button>'
  });
}

async function refreshStatementList(page = 1) {
  await loadStatements(page);
  renderStatementList();
  renderPagination('statement-pagination', state.statementPage, state.statementLastPage, refreshStatementList);
}

function renderStatementList() {
  const container = document.getElementById('statement-list');
  if (state.statements.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada statement</p>';
    return;
  }

  let html = '<div class="list-group">';
  state.statements.forEach(s => {
    const statusClass = {
      'uploaded': 'secondary',
      'decrypted': 'info',
      'parsed': 'warning',
      'imported': 'success',
      'failed': 'danger'
    }[s.status] || 'secondary';

    const icon = s.status === 'parsed' ? 'bi-hourglass-split':
    (s.status === 'imported' ? 'bi-check-circle':
      (s.status === 'failed' ? 'bi-exclamation-circle': 'bi-file-earmark'));

    html += `
    <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1">
    <div class="d-flex align-items-center">
    <i class="${icon} me-2 text-${statusClass}"></i>
    <span class="fw-semibold">${s.original_filename}</span>
    </div>
    <small class="text-muted">Bank: ${s.bank_code || '-'} | Dompet: ${s.wallet?.name || '-'}</small>
    <div>
    <span class="badge bg-${statusClass}">${s.status_label}</span>
    ${s.remaining_count > 0 ? `<span class="badge bg-warning ms-1">${s.remaining_count} belum diimpor</span>`: ''}
    </div>
    <small class="text-muted d-block">${formatDateTime(s.created_at)}</small>
    </div>
    <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown">
    <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
    ${s.status === 'parsed' ? `
    <li><a class="dropdown-item" href="#" onclick="renderPreviewStatementPage(${s.id})">
    <i class="bi bi-eye me-2"></i>Preview & Import
    </a></li>
    `: ''}
    <li><a class="dropdown-item text-danger" href="#" onclick="deleteStatement(${s.id})">
    <i class="bi bi-trash me-2"></i>Hapus
    </a></li>
    </ul>
    </div>
    </div>
    </div>
    `;
  });
  html += '</div>';
  container.innerHTML = html;
}

async function deleteStatement(id) {
  if (!confirm('Hapus statement ini? File dan data transaksi terkait akan dihapus permanen.')) return;
  try {
    tgApp.showLoading('Menghapus...');
    await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/statements/${id}`, {
      method: 'DELETE'
    });
    tgApp.hideLoading();
    tgApp.showToast('Statement dihapus');
    await loadStatements(state.statementPage);
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

// ==================== TRASH PAGES ====================
function navigateToTrash() {
  state.currentPage = 'trash';
  renderTrashPage();
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
}
async function renderTrashPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex align-items-center mb-3">
  <button class="btn btn-link me-2" onclick="navigateTo('transactions')"><i class="bi bi-arrow-left"></i></button>
  <h5 class="mb-0">Tempat Sampah Transaksi</h5>
  </div>
  <div id="trash-list"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions/trashed');
  const trashed = res.data.data || [];
  const container = document.getElementById('trash-list');
  if (!trashed.length) {
    container.innerHTML = '<p class="text-muted text-center">Kosong</p>';
    return;
  }
  container.innerHTML = trashed.map(t => `
    <div class="card mb-2">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div>
    <div>${t.category.name} · ${t.formatted_amount}</div>
    <small class="text-muted">${t.wallet.name} · ${formatDate(t.transaction_date)}</small>
    </div>
    <div>
    <button class="btn btn-sm btn-outline-success" onclick="restoreTransaction(${t.id})"><i class="bi bi-arrow-counterclockwise"></i></button>
    <button class="btn btn-sm btn-outline-danger" onclick="forceDeleteTransaction(${t.id})"><i class="bi bi-trash"></i></button>
    </div>
    </div>
    </div>
    </div>
    `).join('');
}
async function restoreTransaction(id) {
  await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transactions/${id}/restore`, {
    method: 'POST'
  });
  await loadWallets();
  await loadHomeSummary();
  renderTrashPage();
}
async function forceDeleteTransaction(id) {
  if (!confirm('Hapus permanen?')) return;
  await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transactions/${id}/force`, {
    method: 'DELETE'
  });
  renderTrashPage();
}
function navigateToTransferTrash() {
  state.currentPage = 'transfer-trash';
  renderTransferTrashPage();
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
}
async function renderTransferTrashPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex align-items-center mb-3">
  <button class="btn btn-link me-2" onclick="navigateTo('transfers')"><i class="bi bi-arrow-left"></i></button>
  <h5 class="mb-0">Tempat Sampah Transfer</h5>
  </div>
  <div id="transfer-trash-list"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transfers/trashed');
  const trashed = res.data.data || [];
  const container = document.getElementById('transfer-trash-list');
  if (!trashed.length) {
    container.innerHTML = '<p class="text-muted text-center">Kosong</p>';
    return;
  }
  container.innerHTML = trashed.map(t => `
    <div class="card mb-2">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div>
    <div>${t.from_wallet.name} → ${t.to_wallet.name}</div>
    <div class="text-primary">${t.formatted_amount}</div>
    <small class="text-muted">${formatDate(t.transfer_date)}</small>
    </div>
    <div>
    <button class="btn btn-sm btn-outline-success" onclick="restoreTransfer(${t.id})"><i class="bi bi-arrow-counterclockwise"></i></button>
    <button class="btn btn-sm btn-outline-danger" onclick="forceDeleteTransfer(${t.id})"><i class="bi bi-trash"></i></button>
    </div>
    </div>
    </div>
    </div>
    `).join('');
}
async function restoreTransfer(id) {
  await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transfers/${id}/restore`, {
    method: 'POST'
  });
  await loadWallets();
  await loadHomeSummary();
  renderTransferTrashPage();
}
async function forceDeleteTransfer(id) {
  if (!confirm('Hapus permanen?')) return;
  await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transfers/${id}/force`, {
    method: 'DELETE'
  });
  renderTransferTrashPage();
}

// ==================== UTILS ====================
function getCurrencySymbol(code) {
  const c = state.currencies.find(c => c.code === code);
  return c?.symbol || code;
}
function formatNumber(n) {
  return new Intl.NumberFormat('id-ID').format(n);
}
function formatDate(d) {
  return new Date(d).toLocaleDateString('id-ID', {
    day: 'numeric', month: 'short', year: 'numeric'
  });
}
function formatDateFull(d) {
  return new Date(d).toLocaleDateString('id-ID', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
  });
}
function formatDateTime(dt) {
  return new Date(dt).toLocaleString('id-ID', {
    dateStyle: 'short', timeStyle: 'short'
  });
}
function populateSelectWithCurrencies(select, def) {
  select.innerHTML = state.currencies.map(c => `<option value="${c.code}" ${c.code === def ? 'selected': ''}>${c.name} (${c.symbol})</option>`).join('');
}
function formatNumberShort(num) {
  if (num >= 1_000_000_000) return (num / 1_000_000_000).toFixed(1).replace(/\.0$/, '') + 'M';
  if (num >= 1_000_000) return (num / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'JT';
  if (num >= 1000) return (num / 1000).toFixed(1).replace(/\.0$/, '') + 'K';
  return num.toString();
}