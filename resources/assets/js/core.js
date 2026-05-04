// ==================== CORE.JS ====================
// Global Core Object (IIFE) – taruh di file terpisah, jarang disentuh.
const Core = (() => {
  // ========== STATE ==========
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
    pendingAction: null,
    currentFilteredCategories: [],
    googlePollInterval: null,
  };

  // ========== PRIVATE HELPERS ==========
  const getEl = (id) => document.getElementById(id);

  // ========== API WITH INTERCEPT ==========
  async function request(method, url, body = null) {
    const doFetch = () => tgApp.fetchWithAuth(BASE_URL + url, {
      method,
      body: body ? JSON.stringify(body): undefined
    });
    try {
      return await doFetch();
    } catch (error) {
      if (error.status === 403 && ['PIN_REQUIRED', 'PIN_EXPIRED'].includes(error.data?.code)) {
        if (!state.isPinModalShowing) {
          state.isPinModalShowing = true;
          tgApp.hideLoading();
          const ok = await new Promise(resolve => showPinModal(resolve));
          state.isPinModalShowing = false;
          if (ok) return doFetch();
          throw new Error('Verifikasi PIN diperlukan');
        }
        throw new Error('PIN sedang diverifikasi');
      } else if (error.status === 401) {
        handleSessionExpired();
        throw new Error('Sesi berakhir, silakan muat ulang.');
      }
      throw error;
    }
  }

  const api = {
    get: (url) => request('GET', url),
    post: (url, data) => request('POST', url, data),
    put: (url, data) => request('PUT', url, data),
    delete: (url) => request('DELETE', url)
  };

  // ========== FORMATTERS & HELPERS ==========
  const formatNumber = (n) => new Intl.NumberFormat('id-ID').format(n);
  const formatDate = (d) => new Date(d).toLocaleDateString('id-ID', {
    day: 'numeric', month: 'short', year: 'numeric'
  });
  const formatDateFull = (d) => new Date(d).toLocaleDateString('id-ID', {
    weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
  });
  const formatDateTime = (dt) => new Date(dt).toLocaleString('id-ID', {
    dateStyle: 'short', timeStyle: 'short'
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
  const getCurrencySymbol = (code) => {
    const c = state.currencies.find(c => c.code === code);
    return c?.symbol || code;
  };
  const formatTimeAgo = (dateString) => {
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
  };
  const populateSelectWithCurrencies = (select, def) => {
    select.innerHTML = state.currencies.map(c => `<option value="${c.code}" ${c.code === def ? 'selected': ''}>${c.name} (${c.symbol})</option>`).join('');
  };

  // ========== SESSION & PIN ==========
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
      state.pinVerified = false;
      showPinModal((success) => {
        if (success) startSessionTimer();
      });
    }
  }

  function checkPinRequired() {
    const settings = state.userSettings;
    if (settings && settings.pin_enabled) {
      return new Promise((resolve) => {
        showPinModal((pinOk) => {
          if (!pinOk) {
            getEl('loading-overlay').classList.remove('d-none');
            getEl('loading-overlay').innerHTML =
            `<div class="text-center p-4"><i class="bi bi-lock fs-1"></i><h5 class="mt-3">Aplikasi Terkunci</h5><p class="text-muted">Verifikasi PIN diperlukan untuk melanjutkan.</p></div>`;
            resolve(false);
          } else {
            resolve(true);
          }
        });
      });
    }
    return Promise.resolve(true);
  }

  function showPinModal(callback) {
    const modalEl = getEl('pinModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    const form = getEl('pinForm');
    const pinInput = getEl('pinInput');
    const submitBtn = form.querySelector('button[type="submit"]');

    getEl('pinError').classList.add('d-none');
    getEl('pinLockedInfo').classList.add('d-none');
    form.reset();
    pinInput.disabled = false;

    modalEl.addEventListener('shown.bs.modal', () => {
      setTimeout(() => pinInput.focus(),
        150);
    }, {
      once: true
    });

    pinInput.addEventListener('input', () => {
      if (pinInput.value.length === 6) submitPin(callback);
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
        pinInput.value = "";
        if (!state.pinVerified) callback(false);
      });
  }

  async function submitPin(callback) {
    const pinInput = getEl('pinInput');
    const pin = pinInput.value;
    if (!pin || pin.length < 4) return;

    const submitBtn = document.querySelector('#pinForm button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memverifikasi...';

    try {
      const res = await api.post('/api/fintech/settings/verify-pin', {
        pin
      });
      if (res.success) {
        state.pinVerified = true;
        resetSessionTimer();
        getEl('pinError').classList.add('d-none');
        bootstrap.Modal.getInstance(getEl('pinModal')).hide();
        callback(true);
      } else {
        getEl('pinError').textContent = res.message;
        getEl('pinError').classList.remove('d-none');
        pinInput.value = '';
        pinInput.focus();
        if (res.locked_until) showLockoutTimer(res.locked_until);
      }
    } catch (error) {
      getEl('pinError').textContent = error.message || 'Terjadi kesalahan. Coba lagi.';
      getEl('pinError').classList.remove('d-none');
      pinInput.value = "";
      pinInput.focus();
      tgApp.showToast(error.message, 'danger');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = originalText;
    }
  }

  function showLockoutTimer(lockedUntil) {
    const lockedEl = getEl('pinLockedInfo');
    lockedEl.classList.remove('d-none');
    getEl('pinInput').disabled = true;
    const timer = setInterval(() => {
      const now = new Date();
      const lock = new Date(lockedUntil);
      const diff = Math.max(0, lock - now);
      if (diff <= 0) {
        clearInterval(timer);
        lockedEl.classList.add('d-none');
        getEl('pinInput').disabled = false;
      } else {
        const minutes = Math.floor(diff / 60000);
        const seconds = Math.floor((diff % 60000) / 1000);
        lockedEl.textContent = `Akun terkunci. Silakan coba lagi dalam ${minutes}:${seconds.toString().padStart(2, '0')}`;
      }
    },
      1000);
  }

  // ========== DATA LOADING ==========
  async function loadWallets() {
    const res = await api.get('/api/fintech/wallets');
    state.wallets = res.data || [];
    state.totalBalance = state.wallets.reduce((s, w) => s + w.balance,
      0);
  }

  async function loadCategories() {
    const res = await api.get('/api/fintech/categories');
    state.categories = res.data || [];
  }

  async function loadCurrencies() {
    const res = await api.get('/api/fintech/currencies');
    state.currencies = res.data || [];
  }

  async function loadHomeSummary() {
    const res = await api.get('/api/fintech/home-summary');
    state.homeSummary = res.data;
  }

  async function loadUserSettings() {
    try {
      const res = await api.get('/api/fintech/settings');
      state.userSettings = res.data;
    } catch (error) {
      console.warn('Gagal memuat pengaturan:',
        error);
      state.userSettings = {
        default_currency: 'IDR', default_wallet_id: null
      };
    }
  }

  async function loadUnreadNotificationCount() {
    try {
      const res = await api.get('/api/fintech/notifications/unread-count');
      state.unreadNotificationCount = res.count || 0;
      updateNotificationBadge();
    } catch (e) {
      console.error('Gagal memuat jumlah notifikasi:',
        e);
    }
  }

  async function loadNotifications() {
    try {
      const res = await api.get('/api/fintech/notifications');
      state.notifications = res.data || [];
      state.unreadNotificationCount = res.unread_count;
      updateNotificationBadge();
      if (typeof renderNotificationList === 'function') {
        renderNotificationList();
      }
    } catch (e) {
      getEl('notification-list').innerHTML = '<p class="text-muted text-center py-4">Gagal memuat notifikasi.</p>';
    }
  }

  async function loadTransactionsPage(page, filters) {
    let url = `/api/fintech/transactions?per_page=20&page=${page}`;
    if (filters.wallet_id) url += `&wallet_id=${filters.wallet_id}`;
    if (filters.type) url += `&type=${filters.type}`;
    if (filters.month) url += `&month=${filters.month}`;
    const res = await api.get(url);
    state.transactions = res.data.data;
    state.transactionPage = res.data.current_page;
    state.transactionLastPage = res.data.last_page;
    state.transactionSummary = res.summary;
  }

  async function loadTransfersPage(page, walletId) {
    let url = `/api/fintech/transfers?per_page=20&page=${page}`;
    if (walletId) url += `&wallet_id=${walletId}`;
    const res = await api.get(url);
    state.transfers = res.data.data;
    state.transferPage = res.data.current_page;
    state.transferLastPage = res.data.last_page;
  }

  async function loadStatements(page = 1) {
    const res = await api.get(`/api/fintech/statements?page=${page}`);
    state.statements = res.data.data;
    state.statementPage = res.data.current_page;
    state.statementLastPage = res.data.last_page;
  }

  async function loadBudgets() {
    const res = await api.get('/api/fintech/budgets');
    state.budgets = res.data || [];
  }

  // ========== NOTIFICATION BADGE ==========
  function updateNotificationBadge() {
    const badge = getEl('notification-badge');
    if (badge) {
      if (state.unreadNotificationCount > 0) {
        badge.textContent = state.unreadNotificationCount > 99 ? '99+': state.unreadNotificationCount;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
  }

  // ========== NAVIGATION ==========
  let pages = {};

  function setPages(p) {
    pages = p;
  }

  function navigateTo(page) {
    if (!tgApp.getToken()) {
      handleSessionExpired();
      return;
    }
    state.currentPage = page;
    if (state.googlePollInterval) {
      clearInterval(state.googlePollInterval);
      state.googlePollInterval = null;
    }
    document.querySelectorAll('.nav-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.page === page);
    });
    document.querySelectorAll('.dropdown-item.nav-btn').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.page === page);
    });

    if (state.wallets.length === 0 && page !== 'wallets' && page !== 'settings') {
      getEl('main-content').innerHTML = renderEmptyState();
      return;
    }

    if (pages[page]) {
      pages[page]();
      window.scrollTo({
        top: 0, behavior: 'smooth'
      });
    }
  }

  function renderEmptyState() {
    return `
    <div class="container py-4 text-center">
    <i class="bi bi-wallet2 display-1 text-primary"></i>
    <h4 class="mt-3">Belum Ada Dompet</h4>
    <p>Buat dompet pertama untuk mulai mencatat keuangan.</p>
    <button class="btn btn-primary" data-action="add-wallet">
    <i class="bi bi-plus-circle"></i> Buat Dompet
    </button>
    </div>`;
  }

  // ========== RENDER PAGINATION ==========
  function renderPagination(containerId, page, lastPage, onPageChange) {
    if (lastPage <= 1) {
      getEl(containerId).innerHTML = '';
      return;
    }
    tgApp.renderPagination(containerId, page, lastPage, async (newPage) => {
      await onPageChange(newPage);
    });
  }

  async function downloadFile(url, body = null) {
    const token = tgApp.getToken();
    const headers = {
      'Accept': 'application/pdf, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    if (body) {
      headers['Content-Type'] = 'application/json';
    }
    const options = {
      method: body ? 'POST': 'GET',
      headers,
    };
    if (body) options.body = JSON.stringify(body);

    const response = await fetch(BASE_URL + url, options);
    if (!response.ok) {
      let errorData;
      try {
        errorData = await response.json();
      } catch (e) {}
      const message = errorData?.message || `HTTP ${response.status}`;
      const error = new Error(message);
      error.status = response.status;
      throw error;
    }
    return response.blob();
  }

  // Upload via multipart
  async function upload(url, formData) {
    const token = tgApp.getToken();
    const headers = {};
    if (token) {
      headers['Authorization'] = `Bearer ${token}`;
    }
    const doFetch = () => fetch(BASE_URL + url, {
      method: 'POST',
      headers,
      body: formData
    }).then(async (response) => {
      const data = await response.json().catch(() => ({}));
      if (!response.ok) {
        const error = new Error(data.message || 'Upload gagal');
        error.status = response.status;
        error.data = data;
        throw error;
      }
      return data;
    });

    try {
      return await doFetch();
    } catch (error) {
      if (error.status === 403 && ['PIN_REQUIRED', 'PIN_EXPIRED'].includes(error.data?.code)) {
        if (!state.isPinModalShowing) {
          state.isPinModalShowing = true;
          tgApp.hideLoading();
          const ok = await new Promise(resolve => showPinModal(resolve));
          state.isPinModalShowing = false;
          if (ok) return doFetch();
          throw new Error('Verifikasi PIN diperlukan');
        }
        throw new Error('PIN sedang diverifikasi');
      }
      throw error;
    }
  }

  // ========== RESET STATE ==========
  function resetState() {
    state.wallets = [];
    state.categories = [];
    state.currencies = [];
    state.homeSummary = null;
    state.transactions = [];
    state.transactionPage = 1;
    state.transactionLastPage = 1;
    state.transactionSummary = {
      total: 0,
      income: 0,
      expense: 0
    };
    state.transfers = [];
    state.transferPage = 1;
    state.transferLastPage = 1;
    state.totalBalance = 0;
    state.currentPage = 'home';
    state.filters = {
      wallet_id: '',
      type: '',
      month: ''
    };
    state.chartInstances = {
      home: null,
      report: null,
      category: null
    };
    state.userSettings = null;
    state.reportFilter = {
      wallet_id: '',
      periodType: 'all_years',
      date: null,
      month: null,
      year: null
    };
    state.statementPage = 1;
    state.statementLastPage = 1;
    state.statements = [];
    state.pinVerified = false;
    state.pinVerifiedAt = null;
    state.isPinModalShowing = false;
    state.budgets = [];
    state.unreadNotificationCount = 0;
    state.notifications = [];
    state.searchResults = [];
    state.searchKeyword = '';
    state.currentFilter = 'all';
    state.pendingAction = null;
    state.currentFilteredCategories = [];
    state.googlePollInterval = null;

    if (state.sessionTimer) {
      clearTimeout(state.sessionTimer);
      state.sessionTimer = null;
    }
  }

  // ========== SESSION EXPIRED ==========
  function handleSessionExpired() {
    tgApp.clearToken();
    localStorage.removeItem('auth_token');
    resetState();

    const overlay = document.createElement('div');
    overlay.id = 'session-expired-overlay';
    overlay.style.cssText = 'position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.85); display:flex; align-items:center; justify-content:center; z-index:10001; flex-direction:column;';
    overlay.innerHTML = `
    <div class="text-center p-4 text-white">
    <i class="bi bi-box-arrow-in-right display-1"></i>
    <h4 class="mt-3">Sesi Berakhir</h4>
    <p class="text-white-50">Silakan buka kembali aplikasi dari Telegram.</p>
    <button class="btn btn-primary mt-2" onclick="window.Telegram?.WebApp?.openTelegramLink?.('https://t.me/${BOT_USERNAME}')">Buka Ulang</button>
    </div>`;
    document.body.appendChild(overlay);

    const main = document.getElementById('main-content');
    if (main) main.innerHTML = '';
  }

  // ========== PUBLIC API ==========
  return {
    // State
    state,
    api,
    upload,

    // Helpers
    formatNumber,
    formatDate,
    formatDateFull,
    formatDateTime,
    formatNumberShort,
    highlightText,
    getCurrencySymbol,
    formatTimeAgo,
    populateSelectWithCurrencies,

    // Session & PIN
    startSessionTimer,
    resetSessionTimer,
    checkSessionTimeout,
    checkPinRequired,
    showPinModal,
    submitPin,
    showLockoutTimer,
    handleSessionExpired,

    // Data loading
    loadWallets,
    loadCategories,
    loadCurrencies,
    loadHomeSummary,
    loadUserSettings,
    loadUnreadNotificationCount,
    loadNotifications,
    loadTransactionsPage,
    loadTransfersPage,
    loadStatements,
    loadBudgets,

    // Badge
    updateNotificationBadge,

    // Navigation
    setPages,
    navigateTo,
    renderEmptyState,

    // Pagination
    renderPagination,

    // State reset
    resetState,

    downloadFile
  };
})();