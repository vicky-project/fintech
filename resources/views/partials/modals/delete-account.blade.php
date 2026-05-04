<!-- Modal Konfirmasi Hapus Akun -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Konfirmasi Penghapusan</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p>
          Anda akan menghapus <strong>semua data keuangan Anda</strong> secara permanen.
        </p>
        <p>
          Ketik <strong>HAPUS</strong> di bawah untuk melanjutkan:
        </p>
        <input type="text" class="form-control" id="delete-confirm-input" placeholder="Ketik HAPUS">
        <small class="text-muted">Pastikan Anda sudah membackup data jika diperlukan.</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-danger" id="btn-delete-account-confirm" disabled data-action="btn-delete-account-confirm">
          <i class="bi bi-trash me-1"></i> Hapus Permanen
        </button>
      </div>
    </div>
  </div>
</div>

<script>
  window.deleteAccount = function() {
    const modal = new bootstrap.Modal(document.getElementById('deleteAccountModal'));
    const deleteConfirmInput = document.getElementById('delete-confirm-input');
    const btnDeleteAccountConfirm = document.getElementById('btn-delete-account-confirm');
    deleteConfirmInput.value = "";
    btnDeleteAccountConfirm.disabled = true;
    modal.show();
    document.getElementById("delete-confirm-input").oninput = (e) => checkInput(e);
  }
  window.performDeleteAccount = async function(btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Menghapus...';

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

  function checkInput(input) {
    const confirmBtn = document.getElementById('btn-delete-account-confirm');
    if (confirmBtn) {
      confirmBtn.disabled = input.target.value.trim().toUpperCase() !== 'HAPUS';
    }
  }
</script>