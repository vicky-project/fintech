const BASE_URL = '{{ rtrim(config("app.url"), "/") }}';

// ==================== STATE ====================
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

  // PIN Security
  pinVerified: false,
  pinVerifiedAt: null,
  sessionTimeout: 3 * 60 * 1000,
  // 3 menit
  sessionTimer: null,
};

// ==================== UTILS ====================
const api = {
  get: (url) => tgApp.fetchWithAuth(BASE_URL + url),
  post: (url, data) => tgApp.fetchWithAuth(BASE_URL + url, {
    method: 'POST', body: JSON.stringify(data)
  }),
  put: (url, data) => tgApp.fetchWithAuth(BASE_URL + url, {
    method: 'PUT', body: JSON.stringify(data)
  }),
  delete: (url) => tgApp.fetchWithAuth(BASE_URL + url, {
    method: 'DELETE'
  }),
};

const toast = (msg, type = 'success') => tgApp.showToast(msg, type);
const loading = {
  show: (msg) => tgApp.showLoading(msg),
  hide: () => tgApp.hideLoading()
};

const format = {
  number: (n) => new Intl.NumberFormat('id-ID').format(n),
  short: (num) => {
    if (num >= 1e9) return (num / 1e9).toFixed(1).replace(/\.0$/, '') + 'M';
    if (num >= 1e6) return (num / 1e6).toFixed(1).replace(/\.0$/, '') + 'JT';
    if (num >= 1e3) return (num / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return num.toString();
  },
  date: (d) => new Date(d).toLocaleDateString('id-ID', {
    day: 'numeric', month: 'short', year: 'numeric'
  }),
  dateFull: (d) => new Date(d).toLocaleDateString('id-ID', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
  }),
  datetime: (dt) => new Date(dt).toLocaleString('id-ID', {
    dateStyle: 'short', timeStyle: 'short'
  }),
};

const getCurrencySymbol = (code) => state.currencies.find(c => c.code === code)?.symbol || code;

const renderPagination = (containerId, page, lastPage, onPageChange) => {
  if (lastPage <= 1) return document.getElementById(containerId).innerHTML = '';
  tgApp.renderPagination(containerId, page, lastPage, async (newPage) => {
    await onPageChange(newPage);
  });
};

// ==================== DATA LOADING ====================
const loadWallets = () => api.get('/api/fintech/wallets').then(res => {
  state.wallets = res.data || [];
  state.totalBalance = state.wallets.reduce((s, w) => s + w.balance, 0);
});
const loadCategories = () => api.get('/api/fintech/categories').then(res => state.categories = res.data || []);
const loadCurrencies = () => api.get('/api/fintech/currencies').then(res => state.currencies = res.data || []);
const loadHomeSummary = () => api.get('/api/fintech/home-summary').then(res => state.homeSummary = res.data);
const loadUserSettings = () => api.get('/api/fintech/settings').then(res => state.userSettings = res.data).catch(() => state.userSettings = {
  default_currency: 'IDR', default_wallet_id: null, pin_enabled: false
});

const loadTransactionsPage = async (page, filters) => {
  let url = `/api/fintech/transactions?per_page=20&page=${page}`;
  if (filters.wallet_id) url += `&wallet_id=${filters.wallet_id}`;
  if (filters.type) url += `&type=${filters.type}`;
  if (filters.month) url += `&month=${filters.month}`;
  const res = await api.get(url);
  state.transactions = res.data.data;
  state.transactionPage = res.data.current_page;
  state.transactionLastPage = res.data.last_page;
  state.transactionSummary = res.summary;
};

const loadTransfersPage = async (page, walletId) => {
  let url = `/api/fintech/transfers?per_page=20&page=${page}`;
  if (walletId) url += `&wallet_id=${walletId}`;
  const res = await api.get(url);
  state.transfers = res.data.data;
  state.transferPage = res.data.current_page;
  state.transferLastPage = res.data.last_page;
};

const loadStatements = async (page = 1) => {
  const res = await api.get(`/api/fintech/statements?page=${page}`);
  state.statements = res.data.data;
  state.statementPage = res.data.current_page;
  state.statementLastPage = res.data.last_page;
};

// ==================== INIT ====================
document.addEventListener('DOMContentLoaded', async () => {
  await initializeApp();
  document.querySelectorAll('.nav-btn').forEach(btn => btn.addEventListener('click', () => {
    navigateTo(btn.dataset.page); resetSessionTimer();
  }));
  const fab = document.getElementById('fab-button');
  if (fab) {
    fab.style.opacity = '0.7';
    fab.addEventListener('shown.bs.dropdown', () => fab.style.opacity = '1');
    fab.addEventListener('hidden.bs.dropdown', () => fab.style.opacity = '0.7');
  }
  // Event listener untuk reset session timeout pada interaksi pengguna
  ['click',
    'scroll'].forEach(eventType => {
      document.addEventListener(eventType, resetSessionTimer, {
        passive: true
      });
    });
  startSessionTimer();
});

async function initializeApp() {
  const overlay = document.getElementById('loading-overlay');
  try {
    overlay.classList.remove('d-none');
    overlay.innerHTML = `<div class="text-center"><div class="spinner-border text-primary mb-3"></div><p class="text-muted">Memuat data keuangan...</p></div>`;
    await Promise.all([loadWallets(),
      loadUserSettings(),
      loadCategories(),
      loadCurrencies()]);
    if (state.wallets.length > 0) await loadHomeSummary().catch(e => toast('Gagal memuat ringkasan', 'warning'));

    // Periksa apakah PIN diperlukan
    const pinOk = await checkPinRequired();
    if (!pinOk) {
      overlay.innerHTML = `<div class="text-center p-4"><i class="bi bi-lock fs-1"></i><h5 class="mt-3">Aplikasi Terkunci</h5><p class="text-muted">Verifikasi PIN diperlukan untuk melanjutkan.</p></div>`;
      return;
    }

    navigateTo('home');
    overlay.classList.add('d-none');
  } catch (error) {
    console.error('Init error:', error);
    overlay.innerHTML = `<div class="text-center p-4"><i class="bi bi-exclamation-triangle text-danger display-4"></i><h5 class="mt-3">Gagal Memuat Aplikasi</h5><p class="text-muted">${error.message || 'Terjadi kesalahan tidak diketahui.'}</p><button class="btn btn-primary mt-2" onclick="retryInitialization()"><i class="bi bi-arrow-clockwise me-2"></i>Coba Lagi</button></div>`;
    overlay.classList.remove('d-none');
  }
}
function retryInitialization() {
  initializeApp();
}

// ==================== NAVIGATION ====================
function navigateTo(page) {
  state.currentPage = page;
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.page === page));
  document.querySelectorAll('.dropdown-item.nav-btn').forEach(btn => btn.classList.toggle('active', btn.dataset.page === page));
  if (state.wallets.length === 0) return document.getElementById('main-content').innerHTML = renderEmptyState();
  const pages = {
    home: renderHomePage,
    transactions: renderTransactionsPage,
    transfers: renderTransfersPage,
    wallets: renderWalletsPage,
    reports: renderReportsPage,
    settings: renderSettingsPage,
    insights: renderInsightsPage,
    statements: renderStatementsPage
  };
  if (pages[page]) pages[page]();
}

// ... (semua fungsi render halaman lain tetap sama) ...

// ==================== KEAMANAN PIN ====================
async function checkPinRequired() {
  if (state.userSettings && state.userSettings.pin_enabled) {
    return new Promise((resolve) => {
      showPinModal(resolve);
    });
  }
  return true;
}

function showPinModal(callback) {
  const modal = new bootstrap.Modal(document.getElementById('pinModal'));
  modal.show();
  document.getElementById('pinForm').reset();
  document.getElementById('pinError').classList.add('d-none');
  document.getElementById('pinLockedInfo').classList.add('d-none');
  document.getElementById('pinInput').disabled = false;
  document.getElementById('pinInput').focus();

  const form = document.getElementById('pinForm');
  const handleSubmit = async (e) => {
    e.preventDefault();
    await submitPin(callback);
  };
  form.addEventListener('submit', handleSubmit);
  document.getElementById('pinModal').addEventListener('hidden.bs.modal', () => {
    form.removeEventListener('submit', handleSubmit);
    if (!state.pinVerified) callback(false);
  });
}

async function submitPin(callback) {
  const pinInput = document.getElementById('pinInput');
  const pin = pinInput.value;
  if (!pin || pin.length < 4) return;

  try {
    const res = await api.post('/api/fintech/settings/verify-pin', {
      pin
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

// Session Timeout
function startSessionTimer() {
  state.pinVerifiedAt = Date.now();
  clearTimeout(state.sessionTimer);
  state.sessionTimer = setTimeout(checkSessionTimeout,
    state.sessionTimeout);
}

function resetSessionTimer() {
  state.pinVerifiedAt = Date.now();
  clearTimeout(state.sessionTimer);
  state.sessionTimer = setTimeout(checkSessionTimeout,
    state.sessionTimeout);
}

function checkSessionTimeout() {
  if (state.pinVerified && (Date.now() - state.pinVerifiedAt) > state.sessionTimeout) {
    state.pinVerified = false;
    showPinModal((success) => {
      if (success) {
        startSessionTimer();
      }
    });
  }
}

// ==================== PENGATURAN ====================
function renderSettingsPage() {
  const settings = state.userSettings || {
    default_currency: 'IDR', default_wallet_id: '', pin_enabled: false
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
  populateSelectWithCurrencies(document.getElementById('setting-currency'),
    settings.default_currency);
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
  data.pin_enabled = data.pin_enabled === '1';
  if (!data.pin || data.pin.length === 0) {
    delete data.pin;
  }
  try {
    loading.show('Menyimpan...');
    await api.put('/api/fintech/settings', data);
    await loadUserSettings();
    loading.hide();
    toast('Pengaturan disimpan');
    navigateTo('home');
  } catch (error) {
    loading.hide();
    toast(error.message || 'Gagal menyimpan', 'danger');
  }
}

// ==================== GLOBAL FUNCTIONS ====================
window.retryInitialization = retryInitialization;
window.navigateTo = navigateTo;
// ... semua fungsi global lainnya ...