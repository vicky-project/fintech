<div class="modal fade" id="uploadStatementModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Upload Statement Bank</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="uploadStatementForm">
          <div class="mb-3">
            <label class="form-label">Dompet Tujuan <span class="text-danger">*</span></label>
            <select class="form-select" name="wallet_id" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">File Statement <span class="text-danger">*</span></label>
            <input type="file" class="form-control" name="file" accept=".pdf,.xls,.xlsx,.csv" required>
            <small class="text-muted">PDF, Excel, CSV (maks 10MB)</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Password PDF (Opsional)</label>
            <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak ada">
          </div>
        </form>
        <div id="upload-progress" class="d-none">
          <div class="progress">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%">
              Memproses...
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="uploadStatement()">Upload & Proses</button>
      </div>
    </div>
  </div>
</div>

<script>
  function showUploadStatementModal() {
    // Reset form
    const form = document.getElementById('uploadStatementForm');
    if (form) form.reset();

    // Isi dropdown dompet
    const walletSelect = document.querySelector('select[name="wallet_id"]');
    if (walletSelect) {
      walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
      state.wallets.filter(w => w.is_active).forEach(wallet => {
      const option = document.createElement('option');
      option.value = wallet.id;
      option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
      walletSelect.appendChild(option);
      });

      const defaultWalletId = state.userSettings?.default_wallet_id;
      if (defaultWalletId) {
        walletSelect.value = defaultWalletId;
      }
    }

    document.getElementById('upload-progress')?.classList.add('d-none');
    new bootstrap.Modal(document.getElementById('uploadStatementModal')).show();
  }

  async function uploadStatement() {
    const form = document.getElementById('uploadStatementForm');
    const formData = new FormData(form);

    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput.files.length) {
      tgApp.showToast('Pilih file terlebih dahulu', 'danger');
      return;
    }

    const walletId = formData.get('wallet_id');
    if (!walletId) {
      tgApp.showToast('Pilih dompet tujuan', 'danger');
      return;
    }

    // Tampilkan progress
    document.getElementById('upload-progress')?.classList.remove('d-none');
    const submitBtn = form.closest('.modal').querySelector('.btn-primary');
    submitBtn.disabled = true;

    try {
      const res = await fetch(BASE_URL + '/api/fintech/statements/upload', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${tgApp.getToken()}`
        },
        body: formData
      });

      const data = await res.json();

      if (data.success) {
        tgApp.showToast(`Berhasil memproses ${data.data.transaction_count} transaksi`);
        // Tutup modal
        bootstrap.Modal.getInstance(document.getElementById('uploadStatementModal')).hide();
        // Bisa langsung buka preview atau refresh halaman
        // showPreviewStatement(data.data.statement_id);
      } else {
        tgApp.showToast(data.message || 'Gagal memproses statement', 'danger');
      }
    } catch (error) {
      console.error('Upload error:', error);
      tgApp.showToast('Terjadi kesalahan jaringan', 'danger');
    } finally {
      document.getElementById('upload-progress')?.classList.add('d-none');
      submitBtn.disabled = false;
    }
  }
</script>