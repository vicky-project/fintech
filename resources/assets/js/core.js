// ==================== FINAL REFACTORED STRUCTURE ====================

// --- 1. CORE STATE & API (IIFE) ---
const Core = (() => {
  const state = {
    wallets: [],
    categories: [],
    currencies: [],
    homeSummary: null,
    transactions: [],
    transactionPage: 1,
    transactionLastPage: 1,
    transactionSummary: {
      total: 0, income: 0, expense: 0
    },
    transfers: [],
    transferPage: 1,
    transferLastPage: 1,
    totalBalance: 0,
    currentPage: 'home',
    filters: {
      wallet_id: '', type: '', month: ''
    },
    chartInstances: {
      home: null, report: null, category: null
    },
    userSettings: null,
    reportFilter: {
      wallet_id: '', periodType: 'all_years', date: null, month: null, year: null
    },
    statementPage: 1,
    statementLastPage: 1,
    statements: [],
    categoryChartType: 'expense',
    pinVerified: false,
    pinVerifiedAt: null,
    sessionTimeout: 3 * 60 * 1000,
    sessionTimer: null,
    isPinModalShowing: false,
    budgets: [],
    unreadNotificationCount: 0,
    notifications: [],
    searchResults: [],
    searchKeyword: '',
    currentFilter: 'all',
    pendingAction: null
  };

  const api = {
    get: (url) => interceptAndFetch(() => tgApp.fetchWithAuth(BASE_URL + url)),
    post: (url, data) => interceptAndFetch(() => tgApp.fetchWithAuth(BASE_URL + url, {
      method: 'POST', body: JSON.stringify(data)
    })),
    put: (url, data) => interceptAndFetch(() => tgApp.fetchWithAuth(BASE_URL + url, {
      method: 'PUT', body: JSON.stringify(data)
    })),
    delete: (url) => interceptAndFetch(() => tgApp.fetchWithAuth(BASE_URL + url, {
      method: 'DELETE'
    }))
  };

  async function interceptAndFetch(requestFn) {
    try {
      return await requestFn();
    } catch (error) {
      if (error.status === 403 && error.data && (error.data.code === 'PIN_REQUIRED' || error.data.code === 'PIN_EXPIRED')) {
        if (!state.isPinModalShowing) {
          state.isPinModalShowing = true;
          tgApp.hideLoading();
          const pinOk = await new Promise((resolve) => showPinModal(resolve));
          state.isPinModalShowing = false;
          if (pinOk) {
            return await requestFn();
          } else {
            throw new Error('Verifikasi PIN diperlukan');
          }
        } else {
          throw new Error("PIN sedang di verifikasi");
        }
      }
      throw error;
    }
  }

  let pages = {};
  return {
    state,
    api,
    pages,
    setPages(p) {
      pages = p;
    }
  };
})();

// --- 2. GLOBAL HELPERS ---
const getCurrencySymbol = (code) => {
  const c = Core.state.currencies.find(c => c.code === code); return c?.symbol || code;
};
const formatNumber = (n) => new Intl.NumberFormat('id-ID').format(n);
const formatDate = (d) => new Date(d).toLocaleDateString('id-ID', {
  day: 'numeric',
  month: 'short',
  year: 'numeric'
});
const formatDateFull = (d) => new Date(d).toLocaleDateString('id-ID', {
  weekday: 'long',
  day: 'numeric',
  month: 'long',
  year: 'numeric'
});
const formatDateTime = (dt) => new Date(dt).toLocaleString('id-ID', {
  dateStyle: 'short',
  timeStyle: 'short'
});
const formatNumberShort = (num) => {
  if (num >= 1e9) return (num / 1e9).toFixed(1).replace(/\.0$/, '') + 'M';
  if (num >= 1e6) return (num / 1e6).toFixed(1).replace(/\.0$/, '') + 'JT';
  if (num >= 1e3) return (num / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
  return num.toString();
};
const highlightText = (text, keyword) => {
  if (!keyword) return text;
  const escaped = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return text.replace(new RegExp(`(${escaped})`, 'gi'), '<mark class="bg-warning p-0 rounded">$1</mark>');
};
const populateSelectWithCurrencies = (select, def) => {
  select.innerHTML = Core.state.currencies.map(c => `<option value="${c.code}" ${c.code === def ? 'selected': ''}>${c.name} (${c.symbol})</option>`).join('');
};
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
function formatTimeAgo(dateString) {
  const now = new Date();
  const date = new Date(dateString);
  const diffMs = now - date;
  const diffMins = Math.floor(diffMs / 60000);
  if (diffMins < 1) return 'Baru saja';
  if (diffMins < 60) return `${diffMins} menit lalu`;
  const diffHours = Math.floor(diffMins / 60);
  if (diffHours < 24) return `${diffHours} jam lalu`;
  const diffDays = Math.floor(diffHours / 24);
  if (diffDays === 1) return 'Kemarin';
  return `${diffDays} hari lalu`;
}

function toggleQuickActions() {
  const overlay = document.getElementById('quick-actions-overlay');
  const icon = document.getElementById('fab-icon');
  if (overlay.style.opacity === '0' || overlay.style.opacity === '') {
    overlay.style.opacity = '1';
    overlay.style.pointerEvents = 'auto';
    icon.classList.remove('bi-plus-lg');
    icon.classList.add('bi-x-lg');
  } else {
    overlay.style.opacity = '0';
    overlay.style.pointerEvents = 'none';
    icon.classList.remove('bi-x-lg');
    icon.classList.add('bi-plus-lg');
  }
}

// --- 3. PAGE REGISTRATION ---
Core.setPages({
  home: renderHomePage,
  transactions: renderTransactionsPage,
  transfers: renderTransfersPage,
  wallets: renderWalletsPage,
  reports: renderReportsPage,
  settings: renderSettingsPage,
  insights: renderInsightsPage,
  statements: renderStatementsPage,
  budgets: renderBudgetsPage,
  notifications: renderNotificationsPage,
  search: renderSearchPage,
});

// --- 4. NAVIGATION & INIT ---
function navigateTo(page) {
  Core.state.currentPage = page;
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.page === page));
  document.querySelectorAll('.dropdown-item.nav-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.page === page));

  if (Core.state.wallets.length === 0) {
    document.getElementById('main-content').innerHTML = renderEmptyState();
    return;
  }

  if (Core.pages[page]) {
    Core.pages[page]();
    window.scrollTo({
      top: 0, behavior: 'smooth'
    });
  }
}
function setupNavigation() {
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.hasAttribute('data-bs-toggle') && btn.getAttribute('data-bs-toggle') === 'dropdown') {
        return;
      }
      navigateTo(btn.dataset.page);
    });
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

    await loadUserSettings().catch(e => {
      tgApp.showToast("Gagal memuat pengaturan",
        'danger');
      Core.state.userSettings = {
        default_currency: 'IDR',
        default_wallet_id: null
      };
    });

    const pinOk = await checkPinRequired();
    if (!pinOk) {
      loadingOverlay.innerHTML = `
      <div class="text-center p-4">
      <i class="bi bi-lock fs-1"></i>
      <h5 class="mt-3">Aplikasi Terkunci</h5>
      <p class="text-muted">Verifikasi PIN diperlukan untuk melanjutkan.</p>
      </div>`;
      return;
    }

    await Promise.all([
      loadWallets(),
      loadCategories(),
      loadCurrencies()
    ]);

    if (Core.state.wallets.length > 0) {
      await loadHomeSummary().catch(e => tgApp.showToast('Gagal memuat ringkasan', 'warning'));
      loadUnreadNotificationCount();
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
document.addEventListener('DOMContentLoaded', async () => {
  try {
    await initializeApp();
    setupNavigation();

    ['click', 'scroll'].forEach(eventType => {
      document.addEventListener(eventType, resetSessionTimer, {
        passive: true
      });
    });
    startSessionTimer();
  } catch(error) {
    alert(error);
  }
});

// --- 4. PIN & TIMER ---
async function checkPinRequired() {
  const settings = Core.state.userSettings;
  if (settings && settings.pin_enabled) {
    document.getElementById('loading-overlay').classList.add('d-none');

    // Tampilkan modal PIN dan tunggu hasilnya
    const pinOk = await new Promise((resolve) => showPinModal(resolve));

    if (!pinOk) {
      // PIN tidak berhasil, tampilkan pesan terkunci
      document.getElementById('loading-overlay').classList.remove('d-none');
      document.getElementById('loading-overlay').innerHTML =
      `<div class="text-center p-4"><i class="bi bi-lock fs-1"></i><h5 class="mt-3">Aplikasi Terkunci</h5><p class="text-muted">Verifikasi PIN diperlukan untuk melanjutkan.</p></div>`;
      return false;
    }
    document.getElementById('loading-overlay').classList.add('d-none');
  }
  return true;
}
function showPinModal(callback) {
  const modalEl = document.getElementById('pinModal');
  const modal = new bootstrap.Modal(modalEl);
  modal.show();
  const form = document.getElementById('pinForm');
  const pinInput = document.getElementById('pinInput');
  const submitBtn = form.querySelector('button[type="submit"]');

  document.getElementById('pinError').classList.add('d-none');
  document.getElementById('pinLockedInfo').classList.add('d-none');

  form.reset();
  pinInput.disabled = false;

  modalEl.addEventListener('shown.bs.modal', () => {
    setTimeout(()=> {
      pinInput.focus();
    }, 150);
  }, {
    once: true
  });

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
  modalEl.addEventListener('hidden.bs.modal',
    () => {
      form.removeEventListener('submit', handleSubmit);
      if (!Core.state.pinVerified) callback(false);
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
    const res = await Core.api.post('/api/fintech/settings/verify-pin', {
      pin
    });
    if (res.success) {
      Core.state.pinVerified = true;
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
function startSessionTimer() {
  Core.state.pinVerifiedAt = Date.now();
  clearTimeout(Core.state.sessionTimer);
  Core.state.sessionTimer = setTimeout(checkSessionTimeout,
    Core.state.sessionTimeout);
}
function resetSessionTimer() {
  Core.state.pinVerifiedAt = Date.now();
  clearTimeout(Core.state.sessionTimer);
  Core.state.sessionTimer = setTimeout(checkSessionTimeout,
    Core.state.sessionTimeout);
}
function checkSessionTimeout() {
  const now = Date.now();
  if (Core.state.pinVerified && (now - Core.state.pinVerifiedAt) > Core.state.sessionTimeout) {
    // Session habis, tampilkan modal PIN
    Core.state.pinVerified = false;
    showPinModal((success) => {
      if (success) startSessionTimer();
    });
  }
}

// ====== 5. DATA LOADING ======
async function loadWallets() {
  const res = await Core.api.get('/api/fintech/wallets');
  Core.state.wallets = res.data || [];
  Core.state.totalBalance = Core.state.wallets.reduce((s, w) => s + w.balance,
    0);
}
async function loadCategories() {
  const res = await Core.api.get('/api/fintech/categories');
  Core.state.categories = res.data || [];
}
async function loadCurrencies() {
  const res = await Core.api.get('/api/fintech/currencies');
  Core.state.currencies = res.data || [];
}
async function loadHomeSummary() {
  const res = await Core.api.get('/api/fintech/home-summary');
  Core.state.homeSummary = res.data;
}
async function loadNotifications() {
  try {
    const res = await Core.api.get('/api/fintech/notifications');
    Core.state.notifications = res.data || [];
    Core.state.unreadNotificationCount = res.unread_count;
    updateNotificationBadge();
    renderNotificationList();
  } catch (e) {
    document.getElementById('notification-list').innerHTML = '<p class="text-muted text-center py-4">Gagal memuat notifikasi.</p>';
  }
}
async function loadUnreadNotificationCount() {
  try {
    const res = await Core.api.get('/api/fintech/notifications/unread-count');
    Core.state.unreadNotificationCount = res.count || 0;
    updateNotificationBadge();
  } catch (e) {
    console.error('Gagal memuat jumlah notifikasi:',
      e);
  }
}
async function loadUserSettings() {
  try {
    const res = await Core.api.get('/api/fintech/settings');
    Core.state.userSettings = res.data;
  } catch (error) {
    console.warn('Gagal memuat pengaturan:',
      error);
    Core.state.userSettings = {
      default_currency: 'IDR',
      default_wallet_id: null
    };
  }
}
async function loadStatements(page = 1) {
  try {
    const res = await Core.api.get(`/api/fintech/statements?page=${page}`);
    Core.state.statements = res.data.data;
    Core.state.statementPage = res.data.current_page;
    Core.state.statementLastPage = res.data.last_page;
  } catch (error) {
    tgApp.showToast('Gagal memuat statement. '+ error.message,
      'danger');
  }
}

// ====== 6. HOME PAGE ===========
async function renderHomePage() {
  if (!Core.state.homeSummary) {
    await loadHomeSummary();
  }
  const summary = Core.state.homeSummary;
  if (!summary) {
    document.getElementById('main-content').innerHTML = '<p class="text-center py-5">Memuat ringkasan...</p>';
    return;
  }
  alert(JSON.stringify(summary));

  const symbol = getCurrencySymbol(summary.currency);
  const html = `
  <div class="container py-3">
  <div class="card bg-gradient-primary text-white mb-3" style="background: linear-gradient(135deg, #667eea, #764ba2);">
  <div class="card-body">
  <h6>Total Saldo</h6>
  <h2>${symbol} ${formatNumber(summary.total_balance)}</h2>
  <small>${Core.state.wallets.length} dompet aktif</small>
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
  const data = Core.state.homeSummary.weekly_expense;
  if (Core.state.chartInstances.home) Core.state.chartInstances.home.destroy();
  if (!data || data.length === 0) return;
  Core.state.chartInstances.home = new Chart(ctx, {
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
  const transactions = Core.state.homeSummary.recent_transactions;
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

// ====== 7. TRANSACTIONS PAGE =====
async function loadTransactionsPage(page, filters) {
  let url = `/api/fintech/transactions?per_page=20&page=${page}`;
  if (filters.wallet_id) url += `&wallet_id=${filters.wallet_id}`;
  if (filters.type) url += `&type=${filters.type}`;
  if (filters.month) url += `&month=${filters.month}`;
  const res = await Core.api.get(url);
  Core.state.transactions = res.data.data;
  Core.state.transactionPage = res.data.current_page;
  Core.state.transactionLastPage = res.data.last_page;
  Core.state.transactionSummary = res.summary;
}
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
    ${Core.state.wallets.map(w => `<option value="${w.id}" ${w.id == Core.state.filters.wallet_id ? 'selected': ''}>${w.name}</option>`).join('')}
    </select>
    </div>
    <div class="col-4">
    <select class="form-select form-select-sm" id="filter-type">
    <option value="">Semua Tipe</option>
    <option value="income" ${Core.state.filters?.type === 'income' ? 'selected': ''}>Pemasukan</option>
    <option value="expense" ${Core.state.filters?.type === 'expense' ? 'selected': ''}>Pengeluaran</option>
    </select>
    </div>
    <div class="col-4">
    <input type="month" class="form-control form-control-sm" id="filter-month"
    value="${Core.state.filters.month || currentMonth}">
    </div>
    </div>
    <div class="mb-3">
    <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-secondary flex-grow-1" onclick="resetTransactionFilter()">
    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
    </button>
    <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="applyTransactionFilter()">
    <i class="bi bi-funnel me-1"></i>Terapkan
    </button>
    </div>
    </div>
    `,
    listContainerId: 'transaction-list',
    paginationId: 'transaction-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-warning me-1" onclick="showBulkDeleteModal()" title="Hapus Massal">
    <i class="bi bi-calendar-x"></i>
    </button>
    <button class="btn btn-sm btn-outline-danger me-1" onclick="navigateToTrash()">
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
function resetTransactionFilter() {
  // Reset state filter ke default
  Core.state.filters.wallet_id = '';
  Core.state.filters.type = '';
  Core.state.filters.month = '';
  Core.state.transactionPage = 1;

  // Reset tampilan elemen input/select
  document.getElementById('filter-wallet').value = '';
  document.getElementById('filter-type').value = '';
  document.getElementById('filter-month').value = new Date().toISOString().slice(0, 7); // set ke bulan ini

  // Muat ulang data transaksi tanpa filter
  refreshTransactionList();
}
async function refreshTransactionList(page) {
  page = page || Core.state.transactionPage || 1;
  const filters = {
    wallet_id: Core.state.filters.wallet_id,
    type: Core.state.filters.type,
    month: Core.state.filters.month
  };
  await loadTransactionsPage(page, filters);
  updateTransactionStats();
  renderTransactionList();
  renderPagination('transaction-pagination', Core.state.transactionPage, Core.state.transactionLastPage, refreshTransactionList);

  if (Core.state.pendingAction && Core.state.pendingAction.type === 'transaction') {
    const targetId = Core.state.pendingAction.id;
    const exists = Core.state.transactions.some(t => t.id == targetId);

    if (exists) {
      setTimeout(() => editTransaction(targetId), 100);
    } else {
      loadAndEditTransaction(targetId);
    }
    Core.state.pendingAction = null;
  }
}
function updateTransactionStats() {
  const summary = Core.state.transactionSummary;
  const symbol = getCurrencySymbol(Core.state.userSettings?.default_currency || 'IDR');
  document.getElementById('transaction-stats').innerHTML = `
  <div class="col-4"><div class="card p-2 text-center"><small>Total</small><strong>${summary.total}</strong></div></div>
  <div class="col-4"><div class="card p-2 text-center text-success"><small>Masuk</small><strong>${symbol}${formatNumberShort(summary.income)}</strong></div></div>
  <div class="col-4"><div class="card p-2 text-center text-danger"><small>Keluar</small><strong>${symbol}${formatNumberShort(summary.expense)}</strong></div></div>
  `;
}
function renderTransactionList() {
  const filtered = Core.state.transactions;
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
  Core.state.filters.wallet_id = document.getElementById('filter-wallet')?.value || '';
  Core.state.filters.type = document.getElementById('filter-type')?.value || '';
  Core.state.filters.month = document.getElementById('filter-month')?.value || '';
  Core.state.transactionPage = 1;
  refreshTransactionList();
}
function showTransactionDetailModal(id) {
  const trx = Core.state.transactions.find(t => t.id === id);
  if (!trx) {
    tgApp.showToast('Detail transaksi tidak ditemukan.', 'danger');
    return;
  };

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
    await Core.api.delete(`/api/fintech/transactions/${id}`);
    await loadWallets();
    await loadHomeSummary();
    tgApp.hideLoading();
    tgApp.showToast('Transaksi dipindahkan ke tempat sampah');
    if (Core.state.currentPage === 'transactions') {
      await refreshTransactionList();
    } else if (Core.state.currentPage === 'home') {
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
  Core.state.wallets.filter(w => w.is_active).forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    walletSelect.appendChild(option);
  });

  const defaultWallet = Core.state.userSettings?.default_wallet_id;
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
    const res = await Core.api.post('/api/fintech/transactions/bulk-destroy', {
      wallet_id: walletId,
      month
    });

    tgApp.hideLoading();
    tgApp.showToast(res.message,
      res.success ? 'success': 'warning');

    if (res.success) {
      bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal')).hide();
      await loadWallets();
      await loadHomeSummary();
      if (Core.state.currentPage === 'transactions') {
        await refreshTransactionList();
      }
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}
async function loadAndEditTransaction(id) {
  tgApp.showLoading('Mengambil data transaksi...');
  try {
    const res = await Core.api.get(`/api/fintech/transactions/${id}`);
    if (res.success && res.data) {
      Core.state.transactions.push(res.data);
      tgApp.hideLoading();
      // Buka modal edit
      setTimeout(() => editTransaction(id), 50);
    } else {
      tgApp.hideLoading();
      tgApp.showToast('Transaksi tidak ditemukan', 'danger');
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast('Gagal memuat detail transaksi', 'danger');
  }
}

// ====== 8. TRANSFERS PAGE ======
async function loadTransfersPage(page, walletId) {
  let url = `/api/fintech/transfers?per_page=20&page=${page}`;
  if (walletId) url += `&wallet_id=${walletId}`;
  const res = await Core.api.get(url);
  const data = res.data;
  Core.state.transfers = data.data;
  Core.state.transferPage = data.current_page;
  Core.state.transferLastPage = data.last_page;
}
async function renderTransfersPage() {
  await renderListPage( {
    title: 'Transfer',
    icon: 'bi bi-arrow-left-right',
    filterHtml: `
    <div class="mb-3">
    <select class="form-select form-select-sm" id="transfer-wallet-filter" onchange="applyTransferFilter()">
    <option value="">Semua Dompet</option>
    ${Core.state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
    </select>
    </div>
    `,
    listContainerId: 'transfer-list',
    paginationId: 'transfer-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-danger me-1" onclick="navigateToTransferTrash()"><i class="bi bi-trash"></i></button>
    <button class="btn btn-sm btn-primary" onclick="showAddTransferModal()"><i class="bi bi-plus"></i></button>
    `,
    loadFn: refreshTransferList
  });
}
async function refreshTransferList(page = 1) {
  const walletId = document.getElementById('transfer-wallet-filter')?.value || '';
  await loadTransfersPage(page, walletId);
  renderTransferList(Core.state.transfers);
  renderPagination('transfer-pagination', Core.state.transferPage, Core.state.transferLastPage, refreshTransferList);
  if (Core.state.pendingAction && Core.state.pendingAction.type === 'transfer') {
    const targetId = Core.state.pendingAction.id;
    const exists = Core.state.transfers.some(t => t.id == targetId);

    if (exists) {
      setTimeout(() => editTransfer(targetId), 100);
    } else {
      loadAndEditTransfer(targetId);
    }
    Core.state.pendingAction = null;
  }
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
  Core.state.transferPage = 1;
  refreshTransferList();
}
async function deleteTransfer(id) {
  if (!confirm('Pindahkan transfer ke tempat sampah?')) return;
  try {
    tgApp.showLoading('Menghapus...');
    await Core.api.delete(`/api/fintech/transfers/${id}`);
    await loadWallets();
    await loadHomeSummary();
    tgApp.hideLoading();
    tgApp.showToast('Transfer dipindahkan ke tempat sampah');
    if (Core.state.currentPage === 'transfers') {
      await refreshTransferList();
    } else if (Core.state.currentPage === 'home') {
      renderHomePage();
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}
async function loadAndEditTransfer(id) {
  tgApp.showLoading('Mengambil data transfer...');
  try {
    const res = await Core.api.get(`/api/fintech/transfers/${id}`);
    if (res.success && res.data) {
      Core.state.transfers.push(res.data);
      tgApp.hideLoading();
      setTimeout(() => editTransfer(id), 50);
    } else {
      tgApp.hideLoading();
      tgApp.showToast('Transfer tidak ditemukan', 'danger');
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast('Gagal memuat detail transfer', 'danger');
  }
}

// ====== 9. WALLETS PAGE =======
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
  container.innerHTML = Core.state.wallets.map(w => `
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

// ====== 10. REPORTS PAGE =======
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
  <button type="button" class="btn btn-outline-danger ${Core.state.categoryChartType === 'expense' ? 'active': ''}" data-cat-type="expense" onclick="switchCategoryType('expense')">Pengeluaran</button>
  <button type="button" class="btn btn-outline-success ${Core.state.categoryChartType === 'income' ? 'active': ''}" data-cat-type="income" onclick="switchCategoryType('income')">Pemasukan</button>
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
  Core.state.wallets.forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    if (w.id == Core.state.reportFilter.wallet_id) option.selected = true;
    walletSelect.appendChild(option);
  });

  // Set tipe periode
  const periodTypeSelect = document.getElementById('filter-period-type');
  periodTypeSelect.value = Core.state.reportFilter.periodType;

  // Render input detail periode
  renderPeriodDetailInputs();

  // Event saat tipe periode berubah
  periodTypeSelect.onchange = renderPeriodDetailInputs;

  new bootstrap.Modal(document.getElementById('reportFilterModal')).show();
}
function renderPeriodDetailInputs() {
  const type = document.getElementById('filter-period-type').value;
  const container = document.getElementById('filter-period-detail');
  const filter = Core.state.reportFilter;
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

  Core.state.reportFilter.wallet_id = walletId;
  Core.state.reportFilter.periodType = periodType;

  if (periodType === 'all_years') {
    Core.state.reportFilter.date = null;
    Core.state.reportFilter.month = null;
    Core.state.reportFilter.year = null;
  } else if (periodType === 'monthly') {
    Core.state.reportFilter.month = document.getElementById('filter-month').value;
    Core.state.reportFilter.date = null;
    Core.state.reportFilter.year = null;
  } else if (periodType === 'yearly') {
    Core.state.reportFilter.year = document.getElementById('filter-year').value;
    Core.state.reportFilter.date = null;
    Core.state.reportFilter.month = null;
  }

  bootstrap.Modal.getInstance(document.getElementById('reportFilterModal')).hide();
  updateReportPeriodIndicator();
  loadReportCharts();
  loadCategoryChart();
}
async function loadReportCharts() {
  try {
    const filter = Core.state.reportFilter;
    let url = `/api/fintech/reports/${filter.periodType}`;
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

    const res = await Core.api.get(url);
    const data = res.data;
    const ctx = document.getElementById('reportBarChart')?.getContext('2d');
    if (ctx) {
      if (Core.state.chartInstances.report) Core.state.chartInstances.report.destroy();
      Core.state.chartInstances.report = new Chart(ctx, {
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
  const filter = Core.state.reportFilter;
  const params = new URLSearchParams();

  if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);
  params.append('period_type', filter.periodType);
  params.append('type', Core.state.categoryChartType);

  if (filter.periodType === 'monthly' && filter.month) {
    const [year,
      month] = filter.month.split('-');
    params.append('year', parseInt(year, 10));
    params.append('month', parseInt(month, 10));
  } else if (filter.periodType === 'yearly' && filter.year) {
    params.append('year', parseInt(filter.year, 10));
  }
  // all_years tidak perlu year/month

  const url = `/api/fintech/reports/category-summary?${params.toString()}`;

  try {
    const res = await Core.api.get(url);
    const data = res.data;

    const ctx = document.getElementById('categoryChart')?.getContext('2d');
    if (!ctx) return;

    if (Core.state.chartInstances.category) {
      Core.state.chartInstances.category.destroy();
    }

    if (data.values.length === 0) {
      // Tampilkan pesan kosong
      document.getElementById('category-total').innerHTML = 'Tidak ada data untuk periode ini.';
      return;
    }

    Core.state.chartInstances.category = new Chart(ctx, {
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
    `Total ${Core.state.categoryChartType === 'expense' ? 'Pengeluaran': 'Pemasukan'}: ${symbol} ${formatNumber(data.total)}`;

  } catch (error) {
    console.error('Gagal memuat kategori:', error);
  }
}
function switchCategoryType(type) {
  Core.state.categoryChartType = type;
  // Update active button
  document.querySelectorAll('[data-cat-type]').forEach(btn => {
    btn.classList.remove('active');
  });
  document.querySelector(`[data-cat-type="${type}"]`).classList.add('active');
  loadCategoryChart();
  loadCategoryTable();
}
function updateReportPeriodIndicator() {
  const filter = Core.state.reportFilter;
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
    const wallet = Core.state.wallets.find(w => w.id == filter.wallet_id);
    if (wallet) {
      text += ` · ${wallet.name}`;
    }
  }

  indicatorEl.innerHTML = `<i class="${icon} me-1"></i> ${text}`;
}
async function loadCategoryTable() {
  try {
    const filter = Core.state.reportFilter;
    const params = new URLSearchParams();
    if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);
    params.append('type', Core.state.categoryChartType);

    const res = await Core.api.get('/api/fintech/reports/category-table?' + params.toString());
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

// ====== 11. SETTINGS PAGE =====
function renderSettingsPage() {
  const settings = Core.state.userSettings || {
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
  ${Core.state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
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
    await Core.api.put('/api/fintech/settings', data);
    await loadUserSettings();
    tgApp.hideLoading();
    tgApp.showToast('Pengaturan disimpan');
    navigateTo('home');
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
  }
}

// ====== 12. INSIGHTS PAGE ======
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
    const res = await Core.api.get('/api/fintech/insights/full');
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

// ====== 13. STATEMENTS PAGE ======
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
  renderPagination('statement-pagination', Core.state.statementPage, Core.state.statementLastPage, refreshStatementList);
}
function renderStatementList() {
  const container = document.getElementById('statement-list');
  if (Core.state.statements.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada statement</p>';
    return;
  }

  let html = '<div class="list-group">';
  Core.state.statements.forEach(s => {
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
    await Core.api.delete(`/api/fintech/statements/${id}`);
    tgApp.hideLoading();
    tgApp.showToast('Statement dihapus');
    await refreshStatementList(Core.state.statementPage);
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

// ===== 14. BUDGETS PAGE =======
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
    const res = await Core.api.get('/api/fintech/budgets');
    Core.state.budgets = res.data || [];
  } catch (error) {
    Core.state.budgets = [];
    tgApp.showToast('Gagal memuat budget', 'danger');
  }
}
function renderBudgetList() {
  const container = document.getElementById('budget-list');
  if (!container) return;

  if (Core.state.budgets.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada budget.</p>';
    return;
  }

  let html = '';
  Core.state.budgets.forEach(b => {
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

// ===== 15. TRASH PAGES ========
function navigateToTrash() {
  Core.state.currentPage = 'trash';
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
  const res = await Core.api.get('/api/fintech/transactions/trashed');
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
  await Core.api.post(`/api/fintech/transactions/${id}/restore`);
  await loadWallets();
  await loadHomeSummary();
  renderTrashPage();
}
async function forceDeleteTransaction(id) {
  if (!confirm('Hapus permanen?')) return;
  await Core.api.delete(`/api/fintech/transactions/${id}/force`);
  renderTrashPage();
}
function navigateToTransferTrash() {
  Core.state.currentPage = 'transfer-trash';
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
  const res = await Core.api.get('/api/fintech/transfers/trashed');
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
  await Core.api.post(`/api/fintech/transfers/${id}/restore`);
  await loadWallets();
  await loadHomeSummary();
  renderTransferTrashPage();
}
async function forceDeleteTransfer(id) {
  if (!confirm('Hapus permanen?')) return;
  await Core.api.delete(`/api/fintech/transfers/${id}/force`);
  renderTransferTrashPage();
}

// ===== 16. NOTIFICATION ======
function updateNotificationBadge() {
  const badge = document.getElementById('notification-badge');
  if (badge) {
    if (Core.state.unreadNotificationCount > 0) {
      badge.textContent = Core.state.unreadNotificationCount > 99 ? '99+': Core.state.unreadNotificationCount;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  }
}
async function renderNotificationsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Notifikasi</h5>
  <button class="btn btn-sm btn-outline-primary" onclick="markAllNotificationsRead()">
  <i class="bi bi-check-all me-1"></i>Tandai Semua Dibaca
  </button>
  </div>
  <div id="notification-list"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  await loadNotifications();
}
function renderNotificationList() {
  const container = document.getElementById('notification-list');
  if (!Core.state.notifications.length) {
    container.innerHTML = `
    <div class="text-center py-5">
    <i class="bi bi-bell-slash fs-1 text-muted"></i>
    <p class="text-muted mt-2">Belum ada notifikasi</p>
    </div>`;
    return;
  }

  container.innerHTML = Core.state.notifications.map(n => {
    const iconClass = getNotificationIcon(n.type);
    const colorClass = getNotificationColor(n.type);
    const timeAgo = formatTimeAgo(n.created_at);

    return `
    <div class="card mb-2 notification-row ${n.is_read ? 'read': 'unread'}"
    style="cursor: pointer; border: none; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"
    onclick="markNotificationRead(${n.id})">
    <div class="card-body d-flex align-items-start p-3">
    <div class="notification-icon ${colorClass} me-3">
    <i class="${iconClass}"></i>
    </div>
    <div class="flex-grow-1" style="min-width: 0;">
    <div class="notification-header">
    <strong class="notification-title ${n.is_read ? 'read': ''}">${n.title}</strong>
    <span class="notification-time">${timeAgo}</span>
    </div>
    <p class="notification-message mb-0" style="word-wrap: break-word;">${n.message}</p>
    </div>
    ${n.is_read ? '': '<span class="badge bg-primary rounded-pill ms-2" style="width: 8px; height: 8px; padding: 0;"></span>'}
    </div>
    </div>
    `;
  }).join('');
}
function getNotificationIcon(type) {
  const icons = {
    'budget_warning': 'bi-exclamation-triangle',
    'cashflow_warning': 'bi-graph-down',
    'subscription_reminder': 'bi-calendar-check',
  };
  return icons[type] || 'bi-bell';
}
function getNotificationColor(type) {
  const colors = {
    'budget_warning': 'budget-warning',
    'cashflow_warning': 'cashflow-warning',
    'subscription_reminder': 'subscription-reminder',
  };
  return colors[type] || '';
}
async function markNotificationRead(id) {
  try {
    await Core.api.post(`/api/fintech/notifications/${id}/read`);
    const n = Core.state.notifications.find(x => x.id === id);
    if (n && !n.is_read) {
      n.is_read = true;
      Core.state.unreadNotificationCount = Math.max(0, Core.state.unreadNotificationCount - 1);
      updateNotificationBadge();
      renderNotificationList();
    }
  } catch (e) {
    console.error('Gagal menandai notifikasi:', e);
  }
}
async function markAllNotificationsRead() {
  try {
    await Core.api.post('/api/fintech/notifications/read-all');
    Core.state.notifications.forEach(n => n.is_read = true);
    Core.state.unreadNotificationCount = 0;
    updateNotificationBadge();
    renderNotificationList();
    tgApp.showToast('Semua notifikasi ditandai dibaca');
  } catch (e) {
    tgApp.showToast('Gagal', 'danger');
  }
}

// ===== 17. GLOBAL SEARCH ===
async function renderSearchPage() {
  const html = `
  <div class="container py-3">
  <div class="input-group mb-3" id="search-input-group">
  <span class="input-group-text"><i class="bi bi-search"></i></span>
  <input type="search" id="search-input" class="form-control" placeholder="Cari transaksi, transfer..."
  onkeydown="if(event.key==='Enter') performSearch()">
  <button class="btn btn-primary" onclick="performSearch()">Cari</button>
  </div>
  <div id="search-filters" class="btn-group btn-group-sm w-100 mb-3 d-none" role="group">
  <button class="btn btn-outline-primary search-filter-btn active" data-filter="all" onclick="filterSearchResults('all')">
  Semua <span class="filter-badge" id="badge-all">0</span>
  </button>
  <button class="btn btn-outline-primary search-filter-btn" data-filter="transaction" onclick="filterSearchResults('transaction')">
  Transaksi <span class="filter-badge" id="badge-transaction">0</span>
  </button>
  <button class="btn btn-outline-primary search-filter-btn" data-filter="transfer" onclick="filterSearchResults('transfer')">
  Transfer <span class="filter-badge" id="badge-transfer">0</span>
  </button>
  <button class="btn btn-outline-primary search-filter-btn" data-filter="statement" onclick="filterSearchResults('statement')">
  Statement <span class="filter-badge" id="badge-statement">0</span>
  </button>
  </div>
  <div id="search-results">
  <p class="text-muted text-center">Ketik minimal 2 karakter untuk mencari.</p>
  </div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  document.getElementById('search-input').focus();
}
async function performSearch() {
  const q = document.getElementById('search-input').value.trim();
  if (q.length < 2) {
    tgApp.showToast('Minimal 2 karakter', 'warning');
    return;
  }
  Core.state.searchKeyword = q;
  const container = document.getElementById('search-results');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p>Mencari...</p></div>';

  try {
    const res = await Core.api.get(`/api/fintech/search?q=${encodeURIComponent(q)}`);
    Core.state.searchResults = res.data || [];
    updateFilterBadges(Core.state.searchResults);
    document.getElementById('search-filters').classList.remove('d-none');
    filterSearchResults('all'); // tampilkan semua & set active
  } catch (error) {
    container.innerHTML = '<p class="text-muted text-center">Gagal mencari.</p>';
  }
}
function filterSearchResults(filter) {
  Core.state.currentFilter = filter;
  document.querySelectorAll('.search-filter-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.filter === filter);
  });
  renderSearchResults(Core.state.searchResults);
}
function updateFilterBadges(results) {
  document.getElementById('badge-all').textContent = results.length;
  document.getElementById('badge-transaction').textContent = results.filter(i => i.type === 'transaction').length;
  document.getElementById('badge-transfer').textContent = results.filter(i => i.type === 'transfer').length;
  document.getElementById('badge-statement').textContent = results.filter(i => i.type === 'statement').length;
}
function renderSearchResults(results) {
  const container = document.getElementById('search-results');
  let filtered = results;
  if (Core.state.currentFilter !== 'all') {
    filtered = results.filter(item => item.type === Core.state.currentFilter);
  }

  if (!filtered.length) {
    container.innerHTML = '<p class="text-muted text-center py-5">Tidak ditemukan.</p>';
    return;
  }

  container.innerHTML = filtered.map(item => {
    const desc = highlightText(item.description || '', Core.state.searchKeyword);
    if (item.type === 'transaction') {
      return `
      <div class="card search-result-item" onclick="showSearchDetail('${item.type}', ${item.id})">
      <div class="card-body d-flex align-items-center p-3">
      <i class="${item.icon} me-3 fs-4" style="color:${item.color}"></i>
      <div class="flex-grow-1" style="min-width:0;">
      <div class="fw-semibold text-truncate">${desc}</div>
      <small class="text-muted">${item.wallet} · ${formatDate(item.date)}</small>
      </div>
      <span class="${item.transaction_type === 'income' ? 'text-success': 'text-danger'} fw-bold ms-2">${item.amount}</span>
      </div>
      </div>
      `;
    } else if (item.type === 'transfer') {
      return `
      <div class="card search-result-item" onclick="showSearchDetail('${item.type}', ${item.id})">
      <div class="card-body d-flex align-items-center p-3">
      <i class="${item.icon} me-3 fs-4" style="color:${item.color}"></i>
      <div class="flex-grow-1" style="min-width:0;">
      <div class="fw-semibold text-truncate">${desc}</div>
      <small class="text-muted">Transfer · ${formatDate(item.date)}</small>
      </div>
      <span class="fw-bold ms-2">${item.amount}</span>
      </div>
      </div>
      `;
    } else if (item.type === 'statement') {
      return `
      <div class="card search-result-item" onclick="showSearchDetail('${item.type}', ${item.id})">
      <div class="card-body d-flex align-items-center p-3">
      <i class="${item.icon} me-3 fs-4" style="color:${item.color}"></i>
      <div class="flex-grow-1" style="min-width:0;">
      <div class="fw-semibold text-truncate">${desc}</div>
      <small class="text-muted">${item.bank_code} · ${item.wallet} · ${item.status}</small>
      </div>
      <small class="text-muted ms-2">${formatDate(item.date)}</small>
      </div>
      </div>
      `;
    }
  }).join('');
}
function showSearchDetail(type, id) {
  const item = Core.state.searchResults.find(i => i.type === type && i.id == id);
  if (!item) return;

  const title = document.getElementById('searchDetailModalTitle');
  const body = document.getElementById('searchDetailBody');
  const actionBtn = document.getElementById('searchDetailActionBtn');

  if (type === 'transaction') {
    title.textContent = item.category || 'Transaksi';
    body.innerHTML = `
    <div class="text-center mb-3">
    <i class="${item.icon} fs-1" style="color:${item.color}"></i>
    <h5 class="mt-2">${item.category}</h5>
    <span class="badge bg-secondary">${item.transaction_type === 'income' ? 'Pemasukan': 'Pengeluaran'}</span>
    </div>
    <table class="table table-sm">
    <tr><th>Jumlah</th><td class="${item.transaction_type === 'income' ? 'text-success': 'text-danger'} fw-bold">${item.amount}</td></tr>
    <tr><th>Dompet</th><td>${item.wallet}</td></tr>
    <tr><th>Tanggal</th><td>${formatDate(item.date)}</td></tr>
    <tr><th>Deskripsi</th><td>${highlightText(item.description || '-', Core.state.searchKeyword)}</td></tr>
    </table>
    `;
    actionBtn.style.display = 'block';
    actionBtn.textContent = 'Edit Transaksi';
    actionBtn.onclick = () => {
      Core.state.pendingAction = {
        type: 'transaction',
        id: item.id
      };
      bootstrap.Modal.getInstance(document.getElementById('searchDetailModal')).hide();
      navigateTo('transactions');
    };
  } else if (type === 'transfer') {
    title.textContent = 'Transfer';
    body.innerHTML = `
    <div class="text-center mb-3">
    <i class="bi bi-arrow-left-right fs-1 text-info"></i>
    <h5 class="mt-2">Transfer</h5>
    </div>
    <table class="table table-sm">
    <tr><th>Dari</th><td>${item.from_wallet}</td></tr>
    <tr><th>Ke</th><td>${item.to_wallet}</td></tr>
    <tr><th>Jumlah</th><td class="fw-bold">${item.amount}</td></tr>
    <tr><th>Tanggal</th><td>${formatDate(item.date)}</td></tr>
    <tr><th>Deskripsi</th><td>${highlightText(item.description || '-', Core.state.searchKeyword)}</td></tr>
    </table>
    `;
    actionBtn.style.display = 'block';
    actionBtn.textContent = 'Edit Transfer';
    actionBtn.onclick = () => {
      Core.state.pendingAction = {
        type: 'transfer',
        id: item.id
      };
      bootstrap.Modal.getInstance(document.getElementById('searchDetailModal')).hide();
      navigateTo('transfers');
    };
  } else if (type === 'statement') {
    title.textContent = item.description;
    body.innerHTML = `
    <div class="text-center mb-3">
    <i class="bi bi-file-text fs-1 text-secondary"></i>
    <h5 class="mt-2">${item.description}</h5>
    <span class="badge bg-secondary">${item.bank_code}</span>
    </div>
    <table class="table table-sm">
    <tr><th>Bank</th><td>${item.bank_code}</td></tr>
    <tr><th>Dompet</th><td>${item.wallet}</td></tr>
    <tr><th>Status</th><td>${item.status}</td></tr>
    <tr><th>Tanggal Upload</th><td>${formatDate(item.date)}</td></tr>
    </table>
    `;
    actionBtn.style.display = 'block';
    actionBtn.textContent = 'Lihat Statement';
    actionBtn.onclick = () => {
      bootstrap.Modal.getInstance(document.getElementById('searchDetailModal')).hide();
      navigateTo('statements');
    };
  }

  new bootstrap.Modal(document.getElementById('searchDetailModal')).show();
}