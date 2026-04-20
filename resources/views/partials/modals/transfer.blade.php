{{-- Modules/FinTech/Resources/views/partials/modals/transfer.blade.php --}}
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
            <label class="form-label">Catatan (Opsional)</label>
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

  document.addEventListener('DOMContentLoaded', function() {
  const modalEl = document.getElementById('transferModal');

  modalEl.addEventListener('show.bs.modal', function() {
  if (!pendingTransferData) {
  resetTransferForm();
  }
  });

  modalEl.addEventListener('shown.bs.modal', function() {
  if (pendingTransferData) {
  populateEditTransferForm(pendingTransferData);
  pendingTransferData = null;
  }
  });

  modalEl.addEventListener('hidden.bs.modal', function() {
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

    const fromSelect = document.getElementById('from-wallet-select');
    const toSelect = document.getElementById('to-wallet-select');
    fromSelect.disabled = false;
    toSelect.disabled = false;

    populateWalletSelects();
  }

  function populateWalletSelects(selectedFromId = null, selectedToId = null) {
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

    if (selectedFromId) {
      fromSelect.value = selectedFromId;
    }
    if (selectedToId) {
      toSelect.value = selectedToId;
    }
  }

  function populateEditTransferForm(transfer) {
    document.getElementById('transfer-id').value = transfer.id;
    document.getElementById('transferModalTitle').textContent = 'Edit Transfer';

    document.getElementById('transfer-amount').value = transfer.amount;
    document.getElementById('transfer-date').value = transfer.transfer_date;
    document.getElementById('transfer-desc').value = transfer.description || '';

    const fromSelect = document.getElementById('from-wallet-select');
    const toSelect = document.getElementById('to-wallet-select');

    populateWalletSelects(transfer.from_wallet.id, transfer.to_wallet.id);

    fromSelect.disabled = true;
    toSelect.disabled = true;
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

  window.submitTransfer = async function() {
    const form = document.getElementById('transferForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    const isEdit = !!id;

    // Validasi
    if (!data.from_wallet_id || !data.to_wallet_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }
    if (data.from_wallet_id === data.to_wallet_id) {
      tgApp.showToast('Dompet asal dan tujuan tidak boleh sama', 'warning');
      return;
    }

    // Untuk edit, hapus field wallet_id karena dilarang diubah
    if (isEdit) {
      delete data.from_wallet_id;
      delete data.to_wallet_id;
    } else {
      delete data.id;
    }

    try {
      tgApp.showLoading('Memproses transfer...');
      const url = isEdit ? `${BASE_URL}/api/fintech/transfers/${id}`: `${BASE_URL}/api/fintech/transfers`;
      const method = isEdit ? 'PUT': 'POST';

      await tgApp.fetchWithAuth(url, {
      method: method,
      body: JSON.stringify(data)
      });

      await loadWallets();
      await loadTransfers();

      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Transfer diperbarui' : 'Transfer berhasil');

      const modal = bootstrap.Modal.getInstance(document.getElementById('transferModal'));
      modal.hide();

      // Refresh tampilan sesuai halaman aktif
      if (state.currentPage === 'home') {
        renderHomePage();
      } else if (state.currentPage === 'transfers') {
        loadTransferList();
      }
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal memproses transfer', 'danger');
    }
  };
</script>