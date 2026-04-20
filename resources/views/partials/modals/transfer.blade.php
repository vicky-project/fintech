<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Transfer Antar Dompet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="transferForm">
          <div class="mb-3">
            <label class="form-label">Dompet Asal <span class="text-danger">*</span></label>
            <select class="form-select" name="from_wallet_id" id="from-wallet-select" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Dompet Tujuan <span class="text-danger">*</span></label>
            <select class="form-select" name="to_wallet_id" id="to-wallet-select" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="amount" step="0.01" min="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="transaction_date" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Catatan (Opsional)</label>
            <input type="text" class="form-control" name="description">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="submitTransfer()">Transfer</button>
      </div>
    </div>
  </div>
</div>

<script>
  function showTransferModal() {
    const form = document.getElementById('transferForm');
    form.reset();
    document.querySelector('input[name="transaction_date"]').value = new Date().toISOString().split('T')[0];

    const fromSelect = document.getElementById('from-wallet-select');
    const toSelect = document.getElementById('to-wallet-select');

    fromSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    toSelect.innerHTML = '<option value="">Pilih Dompet</option>';

    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option1 = document.createElement('option');
    option1.value = wallet.id;
    option1.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    fromSelect.appendChild(option1);

    const option2 = document.createElement('option');
    option2.value = wallet.id;
    option2.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    toSelect.appendChild(option2);
    });

    new bootstrap.Modal(document.getElementById('transferModal')).show();
  }

  async function submitTransfer() {
    const form = document.getElementById('transferForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());

    if (!data.from_wallet_id || !data.to_wallet_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }
    if (data.from_wallet_id === data.to_wallet_id) {
      tgApp.showToast('Dompet asal dan tujuan tidak boleh sama', 'warning');
      return;
    }

    try {
      tgApp.showLoading('Memproses transfer...');
      await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transfer', {
      method: 'POST',
      body: JSON.stringify(data)
      });

      await loadWallets();
      await loadAllTransactions();

      tgApp.hideLoading();
      tgApp.showToast('Transfer berhasil');
      bootstrap.Modal.getInstance(document.getElementById('transferModal')).hide();

      if (state.currentPage === 'home') renderHomePage();
      else if (state.currentPage === 'transactions') {
        renderTransactionList();
        updateTransactionStats();
      }
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal transfer', 'danger');
    }
  }
</script>