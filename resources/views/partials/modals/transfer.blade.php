<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transferModalTitle">Transfer Antar Dompet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="transferForm">
          <input type="hidden" name="id" id="transfer-id">
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
            <input type="number" class="form-control" name="amount" id="transfer-amount" step="0.01" min="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="transfer_date" id="transfer-date" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Catatan</label>
            <input type="text" class="form-control" name="description" id="transfer-desc">
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
  let editingTransferId = null;
  let pendingTransferData = null;

  document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('transferModal');
  modalEl.addEventListener('show.bs.modal', () => {
  if (!pendingTransferData) resetTransferForm();
  });
  modalEl.addEventListener('shown.bs.modal', () => {
  if (pendingTransferData) {
  populateEditTransferForm(pendingTransferData);
  pendingTransferData = null;
  }
  });
  modalEl.addEventListener('hidden.bs.modal', () => {
  editingTransferId = null;
  pendingTransferData = null;
  document.getElementById('from-wallet-select').disabled = false;
  document.getElementById('to-wallet-select').disabled = false;
  resetTransferForm();
  });
  });

  function resetTransferForm() {
    const form = document.getElementById('transferForm');
    form.reset();
    document.getElementById('transfer-id').value = '';
    document.getElementById('transferModalTitle').textContent = 'Transfer Antar Dompet';
    document.getElementById('transfer-date').value = new Date().toISOString().split('T')[0];
    document.getElementById('from-wallet-select').disabled = false;
    document.getElementById('to-wallet-select').disabled = false;
    populateWalletSelects();
  }

  function populateWalletSelects(selectedFrom = null, selectedTo = null) {
    const fromSelect = document.getElementById('from-wallet-select');
    const toSelect = document.getElementById('to-wallet-select');
    fromSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    toSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(w => {
    const opt1 = document.createElement('option');
    opt1.value = w.id;
    opt1.textContent = `${w.name} (${w.formatted_balance})`;
    fromSelect.appendChild(opt1);
    const opt2 = opt1.cloneNode(true);
    toSelect.appendChild(opt2);
    });
    if (selectedFrom) fromSelect.value = selectedFrom;
    if (selectedTo) toSelect.value = selectedTo;
  }

  function populateEditTransferForm(transfer) {
    document.getElementById('transfer-id').value = transfer.id;
    document.getElementById('transferModalTitle').textContent = 'Edit Transfer';
    document.getElementById('transfer-amount').value = transfer.amount;
    document.getElementById('transfer-date').value = transfer.transfer_date;
    document.getElementById('transfer-desc').value = transfer.description || '';
    populateWalletSelects(transfer.from_wallet.id, transfer.to_wallet.id);
    document.getElementById('from-wallet-select').disabled = true;
    document.getElementById('to-wallet-select').disabled = true;
  }

  window.showAddTransferModal = function() {
    editingTransferId = null;
    pendingTransferData = null;
    resetTransferForm();
    new bootstrap.Modal(document.getElementById('transferModal')).show();
  };

  window.editTransfer = function(id) {
    const transfer = state.transfers.find(t => t.id === id);
    if (!transfer) {
      tgApp.showToast('Transfer tidak ditemukan', 'danger');
      return;
    }
    editingTransferId = id;
    pendingTransferData = transfer;
    new bootstrap.Modal(document.getElementById('transferModal')).show();
  };

  async function submitTransfer() {
    const form = document.getElementById('transferForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    const isEdit = !!id;

    if (!data.from_wallet_id || !data.to_wallet_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }
    if (data.from_wallet_id === data.to_wallet_id) {
      tgApp.showToast('Dompet asal dan tujuan tidak boleh sama', 'warning');
      return;
    }
    if (isEdit) {
      delete data.from_wallet_id;
      delete data.to_wallet_id;
    } else {
      delete data.id;
    }

    try {
      tgApp.showLoading('Memproses...');
      const url = isEdit ? `/api/fintech/transfers/${id}`: `/api/fintech/transfers`;
      if (isEdit) {
        await api.put(url, {data})
      } else {
        await api.post(url, {data})
      }

      await loadWallets();
      await loadHomeSummary();
      if (state.currentPage === 'transfers') await refreshTransferList();

      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Transfer diperbarui' : 'Transfer berhasil');
      bootstrap.Modal.getInstance(document.getElementById('transferModal')).hide();

      if (state.currentPage === 'home') renderHomePage();
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal memproses transfer', 'danger');
    }
  }
</script>