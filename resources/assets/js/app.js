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
  chartInstances: {
    home: null,
    report: null,
    category: null
  },
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
  categoryChartType: 'expense',
  pinVerified: false,
  pinVerifiedAt: null,
  sessionTimeout: 3 * 60 * 1000,
  // 3 menit
  sessionTimer: null,
  budgets: [],
};

// ==================== INITIALIZATION ====================
document.addEventListener('DOMContentLoaded', async () => {
  await initializeApp();
  setupNavigation();
  setupFabOpacity();

  ['click', 'scroll'].forEach(eventType => {
    document.addEventListener(eventType, resetSessionTimer, {
      passive: true
    });
  });
  startSessionTimer();
});

// Fungsi untuk memulai timeout session
function startSessionTimer() {
  state.pinVerifiedAt = Date.now();
  clearTimeout(state.sessionTimer);
  state.sessionTimer = setTimeout(checkSessionTimeout, state.sessionTimeout);
}

function resetSessionTimer() {
  state.pinVerifiedAt = Date.now();
  clearTimeout(state.sessionTimer);
  state.sessionTimer = setTimeout(checkSessionTimeout, state.sessionTimeout);
}

function checkSessionTimeout() {
  const now = Date.now();
  if (state.pinVerified && (now - state.pinVerifiedAt) > state.sessionTimeout) {
    // Session habis, tampilkan modal PIN
    state.pinVerified = false;
    showPinModal((success) => {
      if (success) startSessionTimer();
    });
  }
}

// Cek apakah PIN diperlukan saat aplikasi dibuka
async function checkPinRequired() {
  // Ambil pengaturan user
  const settings = state.userSettings;
  if (settings && settings.pin_enabled) {
    //document.getElementById('loading-overlay').classList.add('d-none');
    return new Promise((resolve)=> showPinModal(resolve));
  }
  return true;
}

function showPinModal(callback) {
  const modal = new bootstrap.Modal(document.getElementById('pinModal'));
  modal.show();
  const form = document.getElementById('pinForm');
  const pinInput = document.getElementById('pinInput');
  const submitBtn = form.querySelector('button[type="submit"]');

  document.getElementById('pinError').classList.add('d-none');
  document.getElementById('pinLockedInfo').classList.add('d-none');

  form.reset();
  pinInput.disabled = false;
  pinInput.focus();
  pinInput.addEventListener('input', () => {
    if (pinInput.value.length === 6) {
      submitPin(callback);
    }
  });

  const handleSubmit = async (e) => {
    e.preventDefault();
    await submitPin(callback);
  };
  form.addEventListener('submit',
    handleSubmit);
  document.getElementById('pinModal').addEventListener('hidden.bs.modal',
    () => {
      form.removeEventListener('submit', handleSubmit);
      if (!state.pinVerified) callback(false);
    });
}

async function submitPin(callback) {
  const pinInput = document.getElementById('pinInput');
  const pin = pinInput.value;
  if (!pin || pin.length < 4) return;

  const submitBtn = document.querySelector('#pinForm button[type="submit"]');
  const spinner = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Memverifikasi...';
  const originalText = submitBtn.innerHTML;
  submitBtn.disabled = true;
  submitBtn.innerHTML = spinner;

  try {
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/settings/verify-pin', {
      method: 'POST',
      body: JSON.stringify({
        pin
      })
    });
    if (res.success) {
      state.pinVerified = true;
      resetSessionTimer();
      document.getElementById('pinError').classList.add('d-none');
      bootstrap.Modal.getInstance(document.getElementById('pinModal')).hide();
      callback(true);
    } else {
      const errorEl = document.getElementById('pinError');
      errorEl.textContent = res.message;
      errorEl.classList.remove('d-none');
      pinInput.value = '';
      pinInput.focus();

      if (res.locked_until) {
        showLockoutTimer(res.locked_until);
      }
    }
  } catch (error) {
    const errorEl = document.getElementById('pinError');
    errorEl.textContent = 'Terjadi kesalahan. Coba lagi.';
    errorEl.classList.remove('d-none');
    tgApp.showToast(error.message);
  } finally {
    submitBtn.disabled = false;
    submitBtn.innerHTML = originalText;
  }
}

function showLockoutTimer(lockedUntil) {
  const lockedEl = document.getElementById('pinLockedInfo');
  lockedEl.classList.remove('d-none');
  document.getElementById('pinInput').disabled = true;
  const timer = setInterval(() => {
    const now = new Date();
    const lock = new Date(lockedUntil);
    const diff = Math.max(0, lock - now);
    if (diff <= 0) {
      clearInterval(timer);
      lockedEl.classList.add('d-none');
      document.getElementById('pinInput').disabled = false;
    } else {
      const minutes = Math.floor(diff / 60000);
      const seconds = Math.floor((diff % 60000) / 1000);
      lockedEl.textContent = `Akun terkunci. Silakan coba lagi dalam ${minutes}:${seconds.toString().padStart(2, '0')}`;
    }
  },
    1000);
}

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
      loadWallets(),
      loadUserSettings(),
      loadCategories(),
      loadCurrencies()
    ]);

    const pinOk = await checkPinRequired();
    if (!pinOk) {
      loadingOverlay.innerHTML = `<div class="text-center p-4"><i class="bi bi-lock fs-1"></i><h5 class="mt-3">Aplikasi Terkunci</h5><p class="text-muted">Verifikasi PIN diperlukan untuk melanjutkan.</p></div>`;
      return;
    }

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
  if (filters.month) url += `&month=${filters.month}`;

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
    tgApp.showToast('Gagal memuat statement. '+ error.message, 'danger');
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
    budgets: renderBudgetsPage,
  };
  if (pages[page]) pages[page]();
  window.scrollTo({
    top: 0, behavior: 'smooth'
  });
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
async function renderHomePage() {
  await loadHomeSummary();
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
  const currentMonth = new Date().toISOString().slice(0, 7); // YYYY-MM

  await renderListPage( {
    title: 'Transaksi',
    icon: 'bi bi-list-ul',
    filterHtml: `
    <div class="row g-2 mb-3" id="transaction-stats"></div>
    <div class="row g-2 mb-3">
    <div class="col-4">
    <select class="form-select form-select-sm" id="filter-wallet">
    <option value="">Semua Dompet</option>
    ${state.wallets.map(w => `<option value="${w.id}" ${w.id == state.filters.wallet_id ? 'selected': ''}>${w.name}</option>`).join('')}
    </select>
    </div>
    <div class="col-4">
    <select class="form-select form-select-sm" id="filter-type">
    <option value="">Semua Tipe</option>
    <option value="income" ${state.filters?.type === 'income' ? 'selected': ''}>Pemasukan</option>
    <option value="expense" ${state.filters?.type === 'expense' ? 'selected': ''}>Pengeluaran</option>
    </select>
    </div>
    <div class="col-4">
    <input type="month" class="form-control form-control-sm" id="filter-month"
    value="${state.filters.month || currentMonth}">
    </div>
    </div>
    <div class="mb-3">
    <button class="btn btn-sm btn-outline-primary w-100" onclick="applyTransactionFilter()">
    <i class="bi bi-funnel me-1"></i>Terapkan Filter
    </button>
    </div>
    `,
    listContainerId: 'transaction-list',
    paginationId: 'transaction-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-warning me-1" onclick="showBulkDeleteModal()" title="Hapus Massal">
    <i class="bi bi-calendar-x"></i>
    </button>
    <button class="btn btn-sm btn-outline-secondary me-1" onclick="navigateToTrash()">
    <i class="bi bi-trash"></i>
    </button>
    <button class="btn btn-sm btn-primary" onclick="showAddTransactionModal()">
    <i class="bi bi-plus"></i>
    </button>
    `,
    loadFn: refreshTransactionList
  });
  updateTransactionStats();
}

async function refreshTransactionList(page) {
  page = page || state.transactionPage || 1;
  const filters = {
    wallet_id: state.filters.wallet_id,
    type: state.filters.type,
    month: state.filters.month
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
    <div class="card mb-2" style="overflow: hidden;">
    <div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1 me-2" onclick="showTransactionDetailModal(${trx.id})" style="cursor: pointer; min-width: 0;">
    <div class="d-flex align-items-center">
    <i class="${trx.category.icon} me-2" style="color:${trx.category.color}; flex-shrink: 0;"></i>
    <div style="min-width: 0;">
    <div class="fw-semibold text-truncate">${trx.category.name}</div>
    <small class="text-muted text-truncate d-block">${trx.wallet.name} · ${formatDate(trx.transaction_date)}</small>
    </div>
    </div>
    ${trx.description ? `
    <div class="mt-1" style="word-break: break-word; overflow-wrap: anywhere;">
    <small class="text-muted">${trx.description}</small>
    </div>`: ''}
    </div>
    <div class="d-flex align-items-center flex-shrink-0">
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
    </div>
    </div>
    `;
  }).join('');
}

function applyTransactionFilter() {
  state.filters.wallet_id = document.getElementById('filter-wallet')?.value || '';
  state.filters.type = document.getElementById('filter-type')?.value || '';
  state.filters.month = document.getElementById('filter-month')?.value || '';
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

function showBulkDeleteModal() {
  // Isi dropdown dompet
  const walletSelect = document.getElementById('bulk-wallet');
  walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
  state.wallets.filter(w => w.is_active).forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    walletSelect.appendChild(option);
  });

  const defaultWallet = state.userSettings?.default_wallet_id;
  if (defaultWallet) walletSelect.value = defaultWallet;

  // Set default bulan ke bulan ini
  const today = new Date();
  const month = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
  document.getElementById('bulk-month').value = month;

  new bootstrap.Modal(document.getElementById('bulkDeleteModal')).show();
}

async function executeBulkDelete() {
  const walletSelect = document.getElementById('bulk-wallet');
  const walletId = walletSelect.value;
  const month = document.getElementById('bulk-month').value;

  if (!walletId) {
    tgApp.showToast('Pilih dompet terlebih dahulu', 'warning');
    return;
  }
  if (!month) {
    tgApp.showToast('Pilih bulan terlebih dahulu', 'warning');
    return;
  }

  const walletName = walletSelect.options[walletSelect.selectedIndex]?.text || 'dompet';
  if (!confirm(`Hapus semua transaksi di dompet "${walletName}" pada bulan ${month}? Tindakan ini dapat dibatalkan di Tempat Sampah.`)) return;

  try {
    tgApp.showLoading('Menghapus...');
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions/bulk-destroy', {
      method: 'POST',
      body: JSON.stringify({
        wallet_id: walletId, month
      })
    });

    tgApp.hideLoading();
    tgApp.showToast(res.message, res.success ? 'success': 'warning');

    if (res.success) {
      bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal')).hide();
      await loadWallets();
      await loadHomeSummary();
      if (state.currentPage === 'transactions') {
        await refreshTransactionList();
      }
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
  <div id="report-period-indicator" class="mb-3 small text-muted"></div>
  <div style="height: 250px;">
  <canvas id="reportBarChart"></canvas>
  </div>
  <div id="trend-summary" class="mt-3"></div>

  <!-- Category Chart -->
  <hr class="my-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
  <h6>Distribusi Kategori</h6>
  <div class="btn-group btn-group-sm" role="group">
  <button type="button" class="btn btn-outline-danger active" data-cat-type="expense" onclick="switchCategoryType('expense')">Pengeluaran</button>
  <button type="button" class="btn btn-outline-success" data-cat-type="income" onclick="switchCategoryType('income')">Pemasukan</button>
  </div>
  </div>
  <div style="height: 350px;">
  <canvas id="categoryChart"></canvas>
  </div>
  <div id="category-total" class="text-center mt-2 small text-muted"></div>
  <!-- Category Table -->
  <div class="mt-4">
  <h6>Detail per Tahun</h6>
  <div id="category-table-container" class="table-responsive" style="max-height: 400px; overflow-y: auto;">
  <div class="text-center py-3">
  <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
  <span class="ms-2">Memuat tabel...</span>
  </div>
  </div>
  </div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  setTimeout(() => {
    updateReportPeriodIndicator();
    loadReportCharts();
    loadCategoryChart();
    loadCategoryTable();
  }, 50);
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
  updateReportPeriodIndicator();
  loadReportCharts();
  loadCategoryChart();
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

async function loadCategoryChart() {
  const filter = state.reportFilter;
  const params = new URLSearchParams();

  if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);
  params.append('period_type', filter.periodType);
  params.append('type', state.categoryChartType);

  if (filter.periodType === 'monthly' && filter.month) {
    const [year,
      month] = filter.month.split('-');
    params.append('year', parseInt(year, 10));
    params.append('month', parseInt(month, 10));
  } else if (filter.periodType === 'yearly' && filter.year) {
    params.append('year', parseInt(filter.year, 10));
  }
  // all_years tidak perlu year/month

  const url = `${BASE_URL}/api/fintech/reports/category-summary?${params.toString()}`;

  try {
    const res = await tgApp.fetchWithAuth(url);
    const data = res.data;

    const ctx = document.getElementById('categoryChart')?.getContext('2d');
    if (!ctx) return;

    if (state.chartInstances.category) {
      state.chartInstances.category.destroy();
    }

    if (data.values.length === 0) {
      // Tampilkan pesan kosong
      document.getElementById('category-total').innerHTML = 'Tidak ada data untuk periode ini.';
      return;
    }

    state.chartInstances.category = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: data.labels,
        datasets: [{
          data: data.values,
          backgroundColor: data.colors,
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom'
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                const value = context.raw;
                const total = context.dataset.data.reduce((a, b) => a+b, 0);
                const percentage = ((value / total) * 100).toFixed(1);
                const symbol = getCurrencySymbol(data.currency);
                return `${context.label}: ${symbol} ${formatNumber(value)} (${percentage}%)`;
              }
            }
          }
        }
      }
    });

    const symbol = getCurrencySymbol(data.currency);
    document.getElementById('category-total').innerHTML =
    `Total ${state.categoryChartType === 'expense' ? 'Pengeluaran': 'Pemasukan'}: ${symbol} ${formatNumber(data.total)}`;

  } catch (error) {
    console.error('Gagal memuat kategori:', error);
  }
}

function switchCategoryType(type) {
  state.categoryChartType = type;
  // Update active button
  document.querySelectorAll('[data-cat-type]').forEach(btn => {
    btn.classList.remove('active');
  });
  document.querySelector(`[data-cat-type="${type}"]`).classList.add('active');
  loadCategoryChart();
  loadCategoryTable();
}

function updateReportPeriodIndicator() {
  const filter = state.reportFilter;
  const indicatorEl = document.getElementById('report-period-indicator');
  if (!indicatorEl) return;

  let text = '';
  let icon = 'bi-calendar3';

  if (filter.periodType === 'all_years') {
    text = 'Semua Tahun';
    icon = 'bi-calendar-range';
  } else if (filter.periodType === 'monthly') {
    if (filter.month) {
      const [year,
        month] = filter.month.split('-');
      const date = new Date(year, month - 1);
      const monthName = date.toLocaleDateString('id-ID', {
        month: 'long', year: 'numeric'
      });
      text = `Bulanan: ${monthName}`;
    } else {
      text = 'Bulanan (belum dipilih)';
    }
  } else if (filter.periodType === 'yearly') {
    if (filter.year) {
      text = `Tahunan: ${filter.year}`;
    } else {
      text = 'Tahunan (belum dipilih)';
    }
  }

  // Tambahkan info dompet jika ada
  if (filter.wallet_id) {
    const wallet = state.wallets.find(w => w.id == filter.wallet_id);
    if (wallet) {
      text += ` · ${wallet.name}`;
    }
  }

  indicatorEl.innerHTML = `<i class="${icon} me-1"></i> ${text}`;
}

async function loadCategoryTable() {
  try {
    const filter = state.reportFilter;
    const params = new URLSearchParams();
    if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);
    params.append('type', state.categoryChartType);

    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/reports/category-table?' + params.toString());
    const data = res.data;
    renderCategoryTable(data);
  } catch (error) {
    document.getElementById('category-table-container').innerHTML = '<p class="text-muted text-center">Gagal memuat tabel.</p>';
  }
}

function renderCategoryTable(data) {
  const container = document.getElementById('category-table-container');
  if (!container) return;

  const {
    years,
    categories,
    totals,
    currency
  } = data;
  if (categories.length === 0) {
    container.innerHTML = '<p class="text-muted text-center">Tidak ada data untuk ditampilkan.</p>';
    return;
  }

  const symbol = getCurrencySymbol(currency);
  let html = `
  <table class="table table-sm table-hover">
  <thead class="table-light sticky-top">
  <tr>
  <th style="min-width: 150px;">Kategori</th>
  ${years.map(y => `<th class="text-end" style="min-width: 100px;">${y}</th>`).join('')}
  <th class="text-end" style="min-width: 110px; white-space: nowrap;">Total</th>
  </tr>
  </thead>
  <tbody>
  `;

  categories.forEach(cat => {
    let rowTotal = 0;
    html += `
    <tr>
    <td style="min-width: 150px;">
    <i class="${cat.icon} me-1" style="color:${cat.color}"></i>
    <small>${cat.name}</small>
    </td>
    ${years.map(y => {
      const val = cat.data[y] || 0;
      rowTotal += val;
      return `<td class="text-end" style="100px;">${val ? formatNumberShort(val): '-'}</td>`;
    }).join('')}
    <td class="text-end fw-semibold" style="min-width: 110px; white-space: nowrap;">${symbol} ${formatNumberShort(rowTotal)}</td>
    </tr>
    `;
  });

  // Baris total
  html += `
  <tr class="table-primary fw-bold">
  <td style="min-width: 150px;">Total</td>
  ${years.map(y => `<td class="text-end" style="min-width: 100px;">${symbol} ${formatNumberShort(totals[y] || 0)}</td>`).join('')}
  <td class="text-end" style="min-width: 110px; white-space: nowrap;">${symbol} ${formatNumberShort(Object.values(totals).reduce((a, b) => a + b, 0))}</td>
  </tr>
  </tbody>
  </table>
  `;
  container.innerHTML = html;
}

// ==================== SETTINGS PAGE ====================
function renderSettingsPage() {
  const settings = state.userSettings || {
    default_currency: 'IDR',
    default_wallet_id: '',
    pin_enabled: false
  };

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
  </div>
  <div class="mb-3">
  <label class="form-label">Dompet Default</label>
  <select class="form-select" name="default_wallet_id" id="setting-wallet">
  <option value="">Tidak Ada</option>
  ${state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
  </select>
  </div>

  <hr>
  <h6>Keamanan</h6>
  <div class="mb-3">
  <div class="form-check form-switch">
  <input class="form-check-input" type="checkbox" name="pin_enabled" id="pin-enabled"
  value="1" ${settings.pin_enabled ? 'checked': ''}
  onchange="togglePinInput()">
  <label class="form-check-label" for="pin-enabled">Aktifkan PIN</label>
  </div>
  </div>
  <div class="mb-3" id="pin-field-group" style="display: ${settings.pin_enabled ? 'block': 'none'};">
  <label class="form-label">PIN (4-6 digit)</label>
  <input type="password" class="form-control" name="pin" id="pin-field"
  inputmode="numeric" pattern="[0-9]*" maxlength="6" minlength="4"
  placeholder="Masukkan PIN baru">
  <small class="text-muted">Kosongkan jika tidak ingin mengubah PIN.</small>
  </div>

  <button type="button" class="btn btn-primary w-100" onclick="saveSettings()">
  Simpan Pengaturan
  </button>
  </form>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;

  populateSelectWithCurrencies(document.getElementById('setting-currency'), settings.default_currency);
  if (settings.default_wallet_id) document.getElementById('setting-wallet').value = settings.default_wallet_id;
}

function togglePinInput() {
  const pinEnabled = document.getElementById('pin-enabled').checked;
  document.getElementById('pin-field-group').style.display = pinEnabled ? 'block': 'none';
  if (!pinEnabled) {
    document.getElementById('pin-field').value = '';
  }
}
async function saveSettings() {
  const form = document.getElementById('settingsForm');
  const formData = new FormData(form);
  const data = Object.fromEntries(formData.entries());

  // Konversi pin_enabled ke boolean
  data.pin_enabled = data.pin_enabled === '1';

  // Hapus pin jika tidak diisi
  if (!data.pin || data.pin.length === 0) {
    delete data.pin;
  }

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
  <!-- Budgets -->
  ${data.budgets && data.budgets.length > 0 ? `
  <div class="card mb-3">
  <div class="card-header">💰 Status Budget</div>
  <div class="list-group list-group-flush">
  ${data.budgets.map(b => {
    const progressClass = b.is_overspent ? 'bg-danger': (b.is_near_limit ? 'bg-warning': 'bg-success');
    return `
    <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
    <div>
    <i class="${b.category.icon} me-2" style="color:${b.category.color}"></i>
    <span class="fw-semibold">${b.category.name}</span>
    ${b.wallet ? `<small class="text-muted d-block">${b.wallet.name}</small>`: ''}
    </div>
    </div>
    <div class="mt-2">
    <div class="d-flex justify-content-between small">
    <span>${b.formatted_spending} / ${b.formatted_amount}</span>
    <span class="${b.is_overspent ? 'text-danger': (b.is_near_limit ? 'text-warning': '')}">${b.percentage}%</span>
    </div>
    <div class="progress" style="height: 6px;">
    <div class="progress-bar ${progressClass}" style="width: ${b.percentage}%"></div>
    </div>
    </div>
    ${b.is_overspent ? '<small class="text-danger">⚠️ Budget terlampaui!</small>': ''}
    ${b.is_near_limit ? '<small class="text-warning">⚡ Mendekati batas budget</small>': ''}
    </div>
    `;
  }).join('')}
  </div>
  </div>
  `: ''}

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
    <div class="flex-grow-1 me-2" style="min-width: 0;">
    <div class="d-flex align-items-start">
    <i class="${icon} me-2 text-${statusClass} flex-shrink-0"></i>
    <span class="fw-semibold" style="overflow-wrap: anywhere;">${s.original_filename}</span>
    </div>
    <small class="text-muted d-block mt-1">Bank: ${s.bank_code || '-'} | Dompet: ${s.wallet?.name || '-'}</small>
    <div class="mt-1">
    <span class="badge bg-${statusClass}">${s.status_label}</span>
    ${s.remaining_count > 0 ? `<span class="badge bg-warning ms-1">${s.remaining_count} belum diimpor</span>`: ''}
    </div>
    <small class="text-muted d-block mt-1">${formatDateTime(s.created_at)}</small>
    </div>
    <div class="dropdown flex-shrink-0">
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
    await refreshStatementList(state.statementPage);
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

// ==================== BUDGETS PAGE ====================
async function renderBudgetsPage() {
  await renderListPage( {
    title: 'Budget & Target',
    icon: 'bi bi-pie-chart',
    listContainerId: 'budget-list',
    paginationId: 'budget-pagination',
    extraHeaderButtons: `<button class="btn btn-sm btn-primary" onclick="showAddBudgetModal()"><i class="bi bi-plus"></i></button>`,
    loadFn: refreshBudgetList
  });
}

async function refreshBudgetList() {
  await loadBudgets();
  renderBudgetList();
}

async function loadBudgets() {
  try {
    const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/budgets');
    state.budgets = res.data || [];
  } catch (error) {
    state.budgets = [];
    tgApp.showToast('Gagal memuat budget', 'danger');
  }
}

function renderBudgetList() {
  const container = document.getElementById('budget-list');
  if (!container) return;

  if (state.budgets.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada budget.</p>';
    return;
  }

  let html = '';
  state.budgets.forEach(b => {
    const progressClass = b.is_overspent ? 'bg-danger': (b.is_near_limit ? 'bg-warning': 'bg-success');
    html += `
    <div class="card mb-3">
    <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
    <div>
    <i class="${b.category.icon} me-2" style="color:${b.category.color}"></i>
    <span class="fw-semibold">${b.category.name}</span>
    ${b.wallet ? `<small class="text-muted d-block">${b.wallet.name}</small>`: ''}
    <small class="text-muted">${b.period_label}</small>
    </div>
    <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown">
    <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="#" onclick="showEditBudgetModal(${b.id})">
    <i class="bi bi-pencil me-2"></i>Edit
    </a></li>
    <li><a class="dropdown-item text-danger" href="#" onclick="deleteBudget(${b.id})">
    <i class="bi bi-trash me-2"></i>Hapus
    </a></li>
    </ul>
    </div>
    </div>
    <div class="mt-2">
    <div class="d-flex justify-content-between small">
    <span>${b.formatted_spending} / ${b.formatted_amount}</span>
    <span class="${b.is_overspent ? 'text-danger': (b.is_near_limit ? 'text-warning': '')}">${b.percentage}%</span>
    </div>
    <div class="progress" style="height: 8px;">
    <div class="progress-bar ${progressClass}" style="width: ${b.percentage}%"></div>
    </div>
    </div>
    </div>
    </div>
    `;
  });
  container.innerHTML = html;
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