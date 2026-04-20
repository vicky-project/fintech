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
  // ==================== STATE MODAL ====================
  let editingTransactionId = null;
  let pendingTransactionData = null; // Data transaksi yang akan diedit

  // ==================== EVENT LISTENER MODAL ====================
  document.addEventListener('DOMContentLoaded', function() {
  const modalEl = document.getElementById('transactionModal');

  // Saat modal akan ditampilkan
  modalEl.addEventListener('show.bs.modal', function() {
  // Reset form jika tidak ada pending data (tambah baru)
  if (!pendingTransactionData) {
  resetTransactionForm();
  }
  });

  // Saat modal sudah tampil sepenuhnya
  modalEl.addEventListener('shown.bs.modal', function() {
  if (pendingTransactionData) {
  populateEditForm(pendingTransactionData);
  pendingTransactionData = null;
  }
  });

  // Saat modal ditutup, bersihkan state
  modalEl.addEventListener('hidden.bs.modal', function() {
  editingTransactionId = null;
  pendingTransactionData = null;
  document.getElementById('wallet-select').disabled = false;
  document.getElementById('wallet-id-hidden').value = '';
  resetTransactionForm();
  });

  // Event listener untuk filter kategori
  const typeSelect = document.getElementById('type-select');
  typeSelect.addEventListener('change', function() {
  filterCategoriesByType();
  });

  document.getElementById('wallet-select').addEventListener('change', function() {
  document.getElementById('wallet-id-hidden').value = this.value;
  });
  });

  // ==================== FUNGSI UTAMA ====================

  // Reset form ke kondisi tambah baru
  function resetTransactionForm() {
    const form = document.getElementById('transactionForm');
    form.reset();
    document.getElementById('transaction-id').value = '';
    document.getElementById('wallet-id-hidden').value = '';
    document.getElementById('transactionModalTitle').textContent = 'Tambah Transaksi';
    document.getElementById('date-input').value = new Date().toISOString().split('T')[0];
    document.getElementById('wallet-select').disabled = false;

    populateWalletSelect();
    filterCategoriesByType(); // tanpa parameter, hanya mengisi opsi
  }

  // Isi dropdown dompet
  function populateWalletSelect(selectedId = null) {
    const select = document.getElementById('wallet-select');
    const hiddenInput = document.getElementById('wallet-id-hidden');
    select.innerHTML = '<option value="">Pilih Dompet</option>';

    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    select.appendChild(option);
    });

    // Set selected setelah semua opsi ditambahkan
    if (selectedId) {
      // Pastikan selectedId dalam bentuk string untuk perbandingan value
      const idStr = String(selectedId);
      select.value = idStr;
      hiddenInput.value = idStr;
    } else {
      hiddenInput.value = "";
      select.value = '';
    }
  }

  // Filter kategori berdasarkan tipe yang dipilih
  // @param {number|null} selectedCategoryId - ID kategori yang ingin dipilih setelah filter
  function filterCategoriesByType(selectedCategoryId = null) {
    const typeSelect = document.getElementById('type-select');
    const categorySelect = document.getElementById('category-select');
    if (!typeSelect || !categorySelect) return;

    const selectedType = typeSelect.value;

    const filtered = state.categories.filter(cat => {
    if (selectedType === 'income') {
    return cat.type === 'income' || cat.type === 'both';
    } else {
    return cat.type === 'expense' || cat.type === 'both';
    }
    });

    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';
    filtered.forEach(cat => {
    const option = document.createElement('option');
    option.value = cat.id;
    option.textContent = cat.name;
    categorySelect.appendChild(option);
    });

    if (selectedCategoryId) {
      const idStr = String(selectedCategoryId);
      const exists = Array.from(categorySelect.options).some(opt => opt.value === idStr);
      if (exists) {
        categorySelect.value = idStr;
      } else {
        console.warn(`Category ID ${selectedCategoryId} tidak ditemukan untuk tipe ${selectedType}`);
      }
    }
  }

  function populateEditForm(transaction) {
    document.getElementById('transaction-id').value = transaction.id;
    document.getElementById('transactionModalTitle').textContent = 'Edit Transaksi';

    document.getElementById('type-select').value = transaction.type;
    document.getElementById('amount-input').value = transaction.amount;
    document.getElementById('date-input').value = transaction.transaction_date;
    document.getElementById('desc-input').value = transaction.description || '';

    // Dompet
    const walletSelect = document.getElementById('wallet-select');
    populateWalletSelect(transaction.wallet.id);
    walletSelect.disabled = true;

    // Kategori
    filterCategoriesByType(transaction.category.id);
  }

  // ==================== TAMBAH & EDIT ====================
  window.showAddTransactionModal = function() {
    editingTransactionId = null;
    pendingTransactionData = null;
    resetTransactionForm();
    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  window.editTransaction = function(id) {
    const trx = state.allTransactions.find(t => t.id === id);
    if (!trx) {
      tgApp.showToast('Transaksi tidak ditemukan', 'danger');
      return;
    }

    editingTransactionId = id;
    pendingTransactionData = trx; // Simpan data untuk diisi nanti

    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  // ==================== SIMPAN ====================
  window.saveTransaction = async function() {
    const form = document.getElementById('transactionForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    const isEdit = !!id;

    if (!data.wallet_id || !data.category_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }

    if (isEdit) delete data.wallet_id;

    if (!isEdit) delete data.id;

    try {
      tgApp.showLoading('Menyimpan...');
      const url = isEdit ? `${BASE_URL}/api/fintech/transactions/${id}`: `${BASE_URL}/api/fintech/transactions`;
      const method = isEdit ? 'PUT': 'POST';

      await tgApp.fetchWithAuth(url, { method, body: JSON.stringify(data) });

      await loadWallets();
      await loadAllTransactions();

      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Transaksi diperbarui' : 'Transaksi berhasil');

      const modal = bootstrap.Modal.getInstance(document.getElementById('transactionModal'));
      modal.hide();

      // Refresh UI
      if (state.currentPage === 'home') {
        renderHomePage();
      } else if (state.currentPage === 'transactions') {
        renderTransactionList();
        updateTransactionStats();
      }
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal menyimpan transaksi', 'danger');
    }
  };
</script>