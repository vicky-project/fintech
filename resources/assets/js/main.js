// ==================== MAIN.JS ====================
// Entry point aplikasi, inisialisasi, event delegation, dan fungsi global
window.showAddTransferModal = () => tgApp.showToast('Modal tambah transfer belum diimplementasikan', 'info');
window.editTransfer = (id) => tgApp.showToast(`Edit transfer ${id} belum diimplementasikan`, 'info');
window.deleteTransfer = async (id) => {
  if (typeof deleteTransfer === 'function') await deleteTransfer(id);
};
window.navigateToTrash = () => {
  if (typeof navigateToTrash === 'function') navigateToTrash();
};
window.navigateToTransferTrash = () => {
  if (typeof navigateToTransferTrash === 'function') navigateToTransferTrash();
};
window.restoreTransfer = async (id) => {
  if (typeof restoreTransfer === 'function') await restoreTransfer(id);
};
window.forceDeleteTransfer = async (id) => {
  if (typeof forceDeleteTransfer === 'function') await forceDeleteTransfer(id);
};
window.toggleQuickActions = () => {
  const overlay = document.getElementById('quick-actions-overlay');
  const icon = document.getElementById('fab-icon');
  if (!overlay || !icon) return;
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
};

// ---------- EVENT DELEGATION (opsional, bisa menggantikan inline onclick secara bertahap) ----------
function handleGlobalClick(e) {
  const target = e.target.closest('[data-action]');
  if (!target) return;
  const action = target.dataset.action;
  const id = target.dataset.id;
  const actions = {
    'navigate': () => Core.navigateTo(target.dataset.page),
    'add-wallet': window.showAddWalletModal,
    'edit-wallet': () => window.editWallet(id),
    'add-transaction': window.showAddTransactionModal,
    'edit-transaction': () => window.editTransaction(id),
    'detail-transaction': () => window.showTransactionDetailModal(id),
    'delete-transaction': () => window.deleteTransaction(id),
    'add-transfer': window.showAddTransferModal,
    'edit-transfer': () => window.editTransfer(id),
    'delete-transfer': () => window.deleteTransfer(id),
    'toggle-quick-actions': window.toggleQuickActions,
    'show-bulk-delete': window.showBulkDeleteModal,
    'execute-bulk-delete': window.executeBulkDelete,
    'apply-transaction-filter': () => window.applyTransactionFilter?.(),
    'reset-transaction-filter': () => window.resetTransactionFilter?.(),
    'apply-transfer-filter': () => window.applyTransferFilter?.(),
    'save-settings': window.saveSettings,
    'show-report-filter': window.showReportFilterModal,
    'switch-category-type': (e) => window.switchCategoryType(target.dataset.catType),
    'perform-search': () => window.performSearch,
    'filter-search-results': () => window.filterSearchResults(target.dataset.filter),
    'show-search-detail': () => window.showSearchDetail(target.dataset.type, id),
    'mark-notification-read': () => window.markNotificationRead(id),
    'mark-all-notifications-read': window.markAllNotificationsRead,
    'navigate-to-trash': window.navigateToTrash,
    'navigate-to-transfer-trash': window.navigateToTransferTrash,
    'restore-transaction': () => window.restoreTransaction(id),
    'force-delete-transaction': () => window.forceDeleteTransaction(id),
    'restore-transfer': () => window.restoreTransfer(id),
    'force-delete-transfer': () => window.forceDeleteTransfer(id),
    // tambahkan aksi lain sesuai kebutuhan
  };
  if (actions[action]) {
    e.preventDefault();
    actions[action](e);
  } else {
    tgApp.showToast(`Action for ${action} not implement yet.`, 'danger');
  }
}

// ---------- EVENT DELEGATION: CHANGE ----------
function handleGlobalChange(e) {
  const target = e.target.closest('[data-action]');
  if (!target) return;
  const action = target.dataset.action;
  const id = target.dataset.id;
  const changeActions = {
    // Settings
    'toggle-pin': () => {
      if (typeof togglePinInput === 'function') {
        togglePinInput();
      } else {
        console.warn('togglePinInput tidak ditemukan');
      }
    },
    // Transfers
    'apply-transfer-filter': () => {
      if (typeof applyTransferFilter === 'function') {
        applyTransferFilter();
      } else {
        console.warn('applyTransferFilter tidak ditemukan');
      }
    },
    'render-period-detail-inputs': () => {
      if (typeof renderPeriodDetailInputs === 'function') {
        renderPeriodDetailInputs();
      } else {
        console.warn('renderPeriodDetailInputs tidak ditemukan');
      }
    },
    'apply-transaction-filter': () => {
      if (typeof applyTransactionFilter === 'function') {
        applyTransactionFilter();
      }
    },
    'switch-category-chart': () => {
      const type = target.dataset.catType; // ambil tipe dari data attribute
      if (type && typeof switchCategoryType === 'function') {
        switchCategoryType(type);
      }
    }
    // Tambahkan aksi lain sesuai kebutuhan
  };

  if (changeActions[action]) {
    changeActions[action](target, e);
  }
}

// ---------- NAVIGASI SETUP ----------
function setupNavigation() {
  document.querySelectorAll('.nav-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      if (btn.hasAttribute('data-bs-toggle') && btn.getAttribute('data-bs-toggle') === 'dropdown') return;
      Core.navigateTo(btn.dataset.page);
    });
  });
}

// ---------- INISIALISASI APLIKASI ----------
async function initializeApp() {
  const overlay = document.getElementById('loading-overlay');
  try {
    overlay.classList.remove('d-none');
    overlay.innerHTML = `<div class="text-center"><div class="spinner-border text-primary mb-3"></div><p class="text-muted">Memuat data keuangan...</p></div>`;

    // 1. Load pengaturan user
    await Core.loadUserSettings().catch(() => {
      tgApp.showToast('Gagal memuat pengaturan, menggunakan default',
        'warning');
    });

    // 2. Cek PIN jika diperlukan
    const pinOk = await Core.checkPinRequired();
    if (!pinOk) {
      overlay.innerHTML = `<div class="text-center p-4"><i class="bi bi-lock fs-1"></i><h5 class="mt-3">Aplikasi Terkunci</h5><p class="text-muted">Verifikasi PIN diperlukan.</p></div>`;
      return;
    }

    // 3. Load data utama
    await Promise.all([
      Core.loadWallets().catch(() => tgApp.showToast('Gagal memuat dompet', 'warning')),
      Core.loadCategories().catch(() => tgApp.showToast('Gagal memuat kategori', 'warning')),
      Core.loadCurrencies().catch(() => tgApp.showToast('Gagal memuat mata uang', 'warning'))
    ]);

    // 4. Jika ada dompet, load ringkasan & notifikasi
    if (Core.state.wallets.length > 0) {
      await Core.loadHomeSummary().catch(() => tgApp.showToast('Gagal memuat ringkasan', 'warning'));
      Core.loadUnreadNotificationCount(); // fire & forget
    }

    // 5. Navigasi ke halaman home
    Core.navigateTo('home');
    overlay.classList.add('d-none');
  } catch (error) {
    console.error('Init error:', error);
    overlay.innerHTML = `
    <div class="text-center p-4">
    <i class="bi bi-exclamation-triangle text-danger display-4"></i>
    <h5 class="mt-3">Gagal Memuat Aplikasi</h5>
    <p class="text-muted">${error.message || 'Terjadi kesalahan tidak diketahui.'}</p>
    <button class="btn btn-primary mt-2" onclick="retryInitialization()">
    <i class="bi bi-arrow-clockwise me-2"></i>Coba Lagi
    </button>
    </div>`;
    overlay.classList.remove('d-none');
  }
}

window.retryInitialization = () => initializeApp();

// ---------- MULAI SETELAH DOM SIAP ----------
document.addEventListener('DOMContentLoaded', () => {
  // Setup event delegation (gantikan inline onclick secara bertahap)
  document.addEventListener('click', handleGlobalClick);
  document.addEventListener('change', handleGlobalChange);

  // Setup navigasi sidebar
  setupNavigation();

  // Mulai session timer
  Core.startSessionTimer();
  ['click', 'scroll'].forEach(eventType => {
    document.addEventListener(eventType, Core.resetSessionTimer, {
      passive: true
    });
  });

  // Jalankan inisialisasi aplikasi
  initializeApp();
});