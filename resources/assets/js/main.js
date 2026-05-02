// ==================== MAIN.JS ====================
// Entry point aplikasi, inisialisasi, event delegation, dan fungsi global
window.performSearch = () => {
  if (typeof performSearch === 'function') performSearch();
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
async function handleGlobalClick(e) {
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
    'navigate-to-trash': window.renderTransactionTrash,
    'navigate-to-transfer-trash': window.renderTransferTrash,
    'restore-transaction': () => window.restoreTransaction(id),
    'force-delete-transaction': () => window.forceDeleteTransaction(id),
    'restore-transfer': () => window.restoreTransfer(id),
    'force-delete-transfer': () => window.forceDeleteTransfer(id),
    'export-data': performExport,
    'toggle-category-badge': () => {
      const id = target.dataset.categoryId;
      if (id) toggleCategoryBadge(id);
    },
    'open-bot-chat': () => {
      const link = target.dataset.botLink;
      try {
        window.Telegram.WebApp.openTelegramLink(link);
      } catch (err) {
        // Fallback: buka di browser biasa jika method tidak tersedia
        window.open(link, '_blank');
      }
    },
    'show-export-guide': () => {
      const modal = new bootstrap.Modal(document.getElementById('exportGuideModal'));
      modal.show();
    },
    'connect-google': async () => {
      const res = await Core.api.get('/api/fintech/oauth/google/redirect');
      if (res.url) {
        if (window.Telegram?.WebApp?.openLink) {
          window.Telegram.WebApp.openLink(res.url);
        } else {
          window.open(res.url, '_blank');
        }
      }
    },
    'backup-data': async () => {
      if (!confirm('Backup data sekarang ?')) return;

      tgApp.showLoading('Memproses permintaan...');
      try {
        const res = await Core.api.post('/api/fintech/backup/send');
        tgApp.showToast(res.message, res.success ? 'success': 'warning');
      } catch(error) {
        tgApp.showToast(error.message || 'Server error', 'danger');
      } finally {
        tgApp.hideLoading();
      }
    },
    'restore-modal': () => {
      new bootstrap.Modal(document.getElementById('restoreModal')).show();
    },
    'restore-data': async () => {
      const form = document.getElementById('formRestore');
      const formData = new FormData(form);

      const fileInput = form.querySelector('input[type="file"]');
      if (!fileInput.files.length) {
        tgApp.showToast('Pilih file terlebih dahulu', 'danger');
        return;
      }
      const token = tgApp.getToken();
      if (!token) {
        tgApp.showToast("Token tidak ditemukan. Silakan refresh aplikasi.", 'danger');
        return;
      }

      if (!confirm('Tindakan ini akan mengganti semua data lama anda. Pastikan data lama anda sudah di backup.')) return;

      const originalText = target.innerHTML;
      target.disabled = true;
      target.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Memproses...';

      try {
        const response = await fetch(BASE_URL + '/api/fintech/backup/restore', {
          headers: {
            'Authorization': `Bearer ${token}`,
            'Accept': 'application/json'
          },
          method: 'POST',
          body: formData
        });

        const result = await response.json();
        if (response.ok && result.success) {
          tgApp.showToast(result.message || 'Data berhasil dipulihkan.', 'success');
          // Tutup modal
          bootstrap.Modal.getInstance(document.getElementById('restoreModal'))?.hide();
        } else {
          tgApp.showToast(result.message || 'Gagal memulihkan data.', 'danger');
        }
      } catch(error) {
        tgApp.showToast(error.message || 'Jaringan bermasalah. Coba lagi', 'danger');
      } finally {
        target.disabled = false;
        target.innerHTML = originalText;
        fileInput.value = '';
      }
    }
    // tambahkan aksi lain sesuai kebutuhan
  };
  if (actions[action]) {
    e.preventDefault();
    actions[action](e);
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
    },
    'change-export-type': () => {
      const type = target.value;
      renderExportFilters(type);
      updateExportFormatAvailability(); // ← panggil di sini
      if (type === 'transactions') updateTransactionCategoryFilter();
    },
    'change-budget-period': () => renderBudgetPeriodInput(),
    'change-transaction-type': () => updateTransactionCategoryFilter(),
    'change-start-date': () => {
      const endEl = document.getElementById('filter-date-to');
      if (endEl && !endEl.value) {
        endEl.value = new Date().toISOString().slice(0, 10);
      }
    },
    'change-export-format': () => {
      if (typeof toggleExportOptions === 'function') {
        toggleExportOptions();
      }
    },
    'restore-input-change': (el) => {
      const file = el.files[0];
      const btnUpload = document.getElementById('btn-upload-restore');

      if (file) {
        if (!file.name.endsWith('.json.gz') && !file.name.endsWith('.json')) {
          alert('Format file tidak didukung. Pilih file .json.gz atau .json');
          el.value = "";
          btnUpload.disabled = true;
          return;
        }
        btnUpload.disabled = false;
        btnUpload.innerHTML = '<i class="bi bi-upload me-1"></i> Upload & Pulihkan';
      } else {
        btnUpload.disabled = true;
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
  overlay.style.zIndex = '10000';
  document.body.style.overflow = 'hidden';
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

    Core.loadUnreadNotificationCount(); // fire & forget

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
  } finally {
    document.body.style.overflow = '';
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