// ==================== MAIN.JS ====================
// Entry point aplikasi, inisialisasi, event delegation, dan fungsi global
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
    'perform-search': performSearch,
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
      const btn = document.getElementById('btn-connect-google');
      if (!btn) return;

      // Simpan teks asli tombol
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Menghubungkan...';

      try {
        // Dapatkan URL otorisasi
        const res = await Core.api.get('/api/fintech/oauth/google/redirect');
        if (!res.url) {
          throw new Error('Gagal mendapatkan URL otorisasi');
        }

        // Buka URL menggunakan mekanisme Telegram jika tersedia
        if (window.Telegram?.WebApp?.openLink) {
          window.Telegram.WebApp.openLink(res.url);
        } else {
          // Fallback untuk browser biasa
          window.open(res.url, '_blank');
        }

        // Mulai polling untuk mengecek status koneksi
        let attempts = 0;
        const maxAttempts = 15; // 15 kali × 2 detik = 30 detik timeout

        const pollInterval = setInterval(async () => {
          attempts++;
          const connected = await checkGoogleConnection(); // perbarui UI jika sukses

          if (connected) {
            clearInterval(pollInterval);
            tgApp.showToast('Akun Google berhasil terhubung!', 'success');
            btn.disabled = false;
            btn.innerHTML = originalText;
            // Pastikan badge "Terhubung" muncul (sudah di‑handle oleh checkGoogleConnection)
          } else if (attempts >= maxAttempts) {
            clearInterval(pollInterval);
            tgApp.showToast('Proses menghubungkan gagal atau dibatalkan.', 'warning');
            btn.disabled = false;
            btn.innerHTML = originalText;
          }
        },
          2000);
        Core.state.googlePollInterval = pollInterval;
      } catch (error) {
        tgApp.showToast(error.message || 'Gagal menghubungkan Google',
          'danger');
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    },
    'disconnect-google': async () => {
      const btn = document.getElementById('btn-disconnect-google');
      if (!btn) return;

      if (!confirm('Putuskan koneksi Google? Anda harus menghubungkan ulang untuk mengekspor ke Google Sheets.')) return;

      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Memutuskan...';

      try {
        await Core.api.delete('/api/fintech/oauth/google/disconnect');
        tgApp.showToast('Koneksi Google telah diputus.', 'success');
        await checkGoogleConnection(); // perbarui tampilan tombol
      } catch (error) {
        tgApp.showToast(error.message || 'Gagal memutus koneksi', 'danger');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
      }
    },
    'backup-data': async () => {
      // Tampilkan modal password opsional
      const modal = new bootstrap.Modal(document.getElementById('backupPasswordModal'));
      modal.show();

      // Ketika tombol "Backup Sekarang" ditekan
      document.getElementById('btn-backup-confirm').onclick = async () => {
        const password = document.getElementById('backup-password').value || null;
        modal.hide();

        tgApp.showLoading('Membuat backup...');
        try {
          const res = await Core.api.post('/api/fintech/backup/send', {
            password
          });
          tgApp.showToast(res.message, 'success');
        } catch (error) {
          tgApp.showToast(error.message || 'Gagal', 'danger');
        } finally {
          tgApp.hideLoading();
        }
      };
    },
    'restore-modal': () => {
      new bootstrap.Modal(document.getElementById('restoreModal')).show();
    },
    'restore-data': async () => {
      const form = document.getElementById('formRestore');
      const formData = new FormData(form);
      const fileInput = form.querySelector('input[type="file"]');
      const password = document.getElementById('restore-password').value || '';

      if (!fileInput.files.length) {
        tgApp.showToast('Pilih file terlebih dahulu', 'danger');
        return;
      }

      if (!confirm('Tindakan ini akan mengganti semua data lama Anda…')) return;

      formData.append('password', password);

      const btn = document.getElementById('btn-upload-restore');
      const originalText = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Memproses...';

      try {
        const result = await Core.upload('/api/fintech/backup/restore', formData);
        await Promise.all([
          Core.loadWallets(),
          Core.loadCategories(),
          Core.loadHomeSummary()
        ]);
        Core.navigateTo('home');

        tgApp.showToast(result.message || 'Data berhasil dipulihkan.', 'success');
        bootstrap.Modal.getInstance(document.getElementById('restoreModal'))?.hide();
      } catch (error) {
        tgApp.showToast(error.message || 'Gagal memulihkan data.', 'danger');
      } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
        fileInput.value = '';
        password.value = '';
      }
    },
    'switch-date-filter': switchDateFilter(target.dataset.filter),
    'show-info': () => {
      const infoType = target.dataset.info;
      let title = '',
      body = '';

      switch (infoType) {
        case 'pin':
          title = 'Mengapa PIN Penting?';
          body = `
          <p>Restore data akan <strong>menghapus seluruh data Anda saat ini</strong> dan menggantinya dengan data dari file backup.</p>
          <p>Tanpa PIN, siapa pun yang memegang perangkat login Anda dapat melakukan perubahan data, penghapusan data, dan restore data. Aktifkan PIN untuk mencegah hal ini.</p>
          <p class="mb-0">Anda akan diminta PIN setiap kali mengakses aplikasi atau melakukan restore data.</p>
          `;
          break;
        case 'pin_guide':
          title = 'Tips Keamanan PIN';
          body = `
          <p>Gunakan PIN yang mudah diingat tapi sulit ditebak.</p>
          <p>Jangan gunakan tanggal lahir atau kombinasi sederhana (1234, 0000).</p>
          <p class="mb-0">Jika Anda lupa PIN, hubungi <strong>@${BOT_USERNAME}</strong> untuk bantuan.</p>
          `;
          break;
        case 'backup':
          title = 'Tentang Backup & Restore';
          body = `
          <p>File backup berisi <strong>seluruh data keuangan Anda</strong> (dompet, transaksi, budget, dll).</p>
          <p>Simpan di tempat aman dan jangan bagikan ke siapa pun.</p>
          <p>Restore hanya bisa dilakukan ke akun Telegram yang sama.</p>
          <p>Restore akan <strong>menghapus data saat ini</strong>. Pastikan Anda sudah backup terlebih dahulu.</p>
          <p class="mb-0">Aktifkan PIN untuk mencegah restore data tanpa izin.</p>
          `;
          break;
      }

      document.getElementById('infoModalTitle').textContent = title;
      document.getElementById('infoModalBody').innerHTML = body;
      new bootstrap.Modal(document.getElementById('infoModal')).show();
    },
    'delete-account': () => {
      const modal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
      modal.show();
      document.getElementById('delete-confirm-input').value = '';
      document.getElementById('btn-delete-account-confirm').disabled = true;

      // Validasi input "HAPUS"
      document.getElementById('delete-confirm-input').addEventListener('input', (e) => {
        const confirmBtn = document.getElementById('btn-delete-account-confirm');
        if (confirmBtn) {
          confirmBtn.disabled = e.target.value.trim().toUpperCase() !== 'HAPUS';
        }
    });
  },
  'btn-delete-account-confirm': async () => {
    target.disabled = true;
    target.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Menghapus...';

    try {
      const res = await Core.api.delete('/api/fintech/setting/account/delete');
      tgApp.showToast(res.message || 'Akun berhasil dihapus.',
        'success');

      // Bersihkan state & redirect
      localStorage.removeItem('auth_token');
      Core.state.userSettings = null;
      Core.state.wallets = [];
      Core.state.transactions = [];
      Core.state.homeSummary = null;

      // Tampilkan pesan perpisahan
      document.getElementById('main-content').innerHTML = `
      <div class="container py-5 text-center">
      <i class="bi bi-check-circle text-success display-1"></i>
      <h4 class="mt-3">Akun Telah Dihapus</h4>
      <p class="text-muted">Seluruh data Anda telah dihapus permanen.</p>
      <p class="text-muted small">Anda dapat menutup aplikasi ini.</p>
      </div>`;

      bootstrap.Modal.getInstance(document.getElementById('deleteAccountModal'))?.hide();
    } catch (error) {
      tgApp.showToast(error.message || 'Gagal menghapus akun',
        'danger');
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-trash me-1"></i> Hapus Permanen';
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
'change-transaction-filter': () => {
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
await Core.loadUserSettings();

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
Core.loadCurrencies().catch(() => tgApp.showToast('Gagal memuat mata uang', 'warning')),
Core.loadHomeSummary().catch(() => tgApp.showToast('Gagal memuat ringkasan', 'warning'))
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