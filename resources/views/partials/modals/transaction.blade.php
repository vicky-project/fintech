<div class="modal fade" id="transactionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="transactionModalTitle">Tambah Transaksi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="transactionForm">
          <input type="hidden" name="id" id="transaction-id">
          <input type="hidden" name="wallet_id" id="wallet-id-hidden">

          <div class="mb-3">
            <label class="form-label">Dompet <span class="text-danger">*</span></label>
            <select class="form-select" id="wallet-select" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe <span class="text-danger">*</span></label>
            <select class="form-select" name="type" id="type-select" required>
              <option value="income">Pemasukan</option>
              <option value="expense">Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori <span class="text-danger">*</span></label>
            <select class="form-select" name="category_id" id="category-select" required>
              <option value="">Pilih Kategori</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="amount" id="amount-input" step="0.01" min="0.01" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal <span class="text-danger">*</span></label>
            <input type="date" class="form-control" name="transaction_date" id="date-input" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi</label>
            <input type="text" class="form-control" name="description" id="desc-input">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="saveTransaction()">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
  let editingTransactionId = null;
  let pendingTransactionData = null;

  document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('transactionModal');
  modalEl.addEventListener('show.bs.modal', () => {
  if (!pendingTransactionData) resetTransactionForm();
  });
  modalEl.addEventListener('shown.bs.modal', () => {
  if (pendingTransactionData) {
  populateEditForm(pendingTransactionData);
  pendingTransactionData = null;
  }
  });
  modalEl.addEventListener('hidden.bs.modal', () => {
  editingTransactionId = null;
  pendingTransactionData = null;
  document.getElementById('wallet-select').disabled = false;
  resetTransactionForm();
  });
  document.getElementById('type-select').addEventListener('change', filterCategoriesByType);
  document.getElementById('wallet-select').addEventListener('change', function() {
  document.getElementById('wallet-id-hidden').value = this.value;
  });
  });

  function resetTransactionForm() {
    const form = document.getElementById('transactionForm');
    form.reset();
    document.getElementById('transaction-id').value = '';
    document.getElementById('wallet-id-hidden').value = '';
    document.getElementById('transactionModalTitle').textContent = 'Tambah Transaksi';
    document.getElementById('date-input').value = new Date().toISOString().split('T')[0];
    document.getElementById('wallet-select').disabled = false;
    populateWalletSelect();
    filterCategoriesByType();
  }

  function populateWalletSelect(selectedId = null) {
    const select = document.getElementById('wallet-select');
    const hidden = document.getElementById('wallet-id-hidden');
    select.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = `${w.name} (${w.formatted_balance})`;
    select.appendChild(option);
    });

    const defaultWalletId = selectedId || state.userSettings?.default_wallet_id;
    if (defaultWalletId) {
      select.value = defaultWalletId;
      hidden.value = defaultWalletId;
    } else {
      hidden.value = '';
    }
  }

  function filterCategoriesByType(selectedId = null) {
    const type = document.getElementById('type-select').value;
    const categorySelect = document.getElementById('category-select');
    const filtered = state.categories.filter(c =>
    type === 'income' ? (c.type === 'income' || c.type === 'both')
    : (c.type === 'expense' || c.type === 'both')
    );
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';
    filtered.forEach(c => {
    const option = document.createElement('option');
    option.value = c.id;
    option.textContent = c.name;
    categorySelect.appendChild(option);
    });
    if (selectedId) categorySelect.value = selectedId;
  }

  function populateEditForm(trx) {
    document.getElementById('transaction-id').value = trx.id;
    document.getElementById('transactionModalTitle').textContent = 'Edit Transaksi';
    document.getElementById('type-select').value = trx.type;
    document.getElementById('amount-input').value = trx.amount;
    document.getElementById('date-input').value = trx.transaction_date;
    document.getElementById('desc-input').value = trx.description || '';
    const walletSelect = document.getElementById('wallet-select');
    populateWalletSelect(trx.wallet.id);
    walletSelect.disabled = true;
    filterCategoriesByType(trx.category.id);
  }

  window.showAddTransactionModal = function() {
    editingTransactionId = null;
    pendingTransactionData = null;
    resetTransactionForm();
    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  window.editTransaction = function(id) {
    const trx = state.transactions.find(t => t.id === id);
    if (!trx) {
      tgApp.showToast('Transaksi tidak ditemukan', 'danger');
      return;
    }
    editingTransactionId = id;
    pendingTransactionData = trx;
    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  async function saveTransaction() {
    const form = document.getElementById('transactionForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    const isEdit = !!id;

    if (isEdit) delete data.wallet_id;
    else delete data.id;

    if (!data.wallet_id && !isEdit) {
      tgApp.showToast('Dompet harus dipilih', 'danger');
      return;
    }
    if (!data.category_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }

    try {
      tgApp.showLoading('Menyimpan...');
      const url = isEdit ? `${BASE_URL}/api/fintech/transactions/${id}`: `${BASE_URL}/api/fintech/transactions`;
      const method = isEdit ? 'PUT': 'POST';
      await tgApp.fetchWithAuth(url, { method, body: JSON.stringify(data) });

      await loadWallets();
      await loadHomeSummary();
      if (state.currentPage === 'transactions') await refreshTransactionList();

      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Transaksi diperbarui' : 'Transaksi berhasil', 'success');
      bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();

      if (state.currentPage === 'home') renderHomePage();
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
    }
  }
</script>