<div class="modal fade" id="transactionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tambah Transaksi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="transactionForm">
          <input type="hidden" name="id" id="transaction-id">

          <div class="mb-3">
            <label class="form-label">Dompet <span class="text-danger">*</span></label>
            <select class="form-select" name="wallet_id" id="wallet-select" required>
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
  // State untuk menyimpan ID transaksi yang sedang diedit
  let editingTransactionId = null;

  // Fungsi untuk membuka modal tambah transaksi
  window.showAddTransactionModal = function() {
    editingTransactionId = null;
    document.getElementById('transaction-id').value = '';
    document.querySelector('#transactionModal .modal-title').textContent = 'Tambah Transaksi';

    const form = document.getElementById('transactionForm');
    form.reset();
    document.getElementById('date-input').value = new Date().toISOString().split('T')[0];

    // Aktifkan dropdown dompet
    const walletSelect = document.getElementById('wallet-select');
    walletSelect.disabled = false;
    populateWalletSelect();

    // Reset kategori
    const categorySelect = document.getElementById('category-select');
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';

    // Pasang event listener tipe
    const typeSelect = document.getElementById('type-select');
    typeSelect.removeEventListener('change', filterCategoriesByType);
    typeSelect.addEventListener('change', filterCategoriesByType);
    filterCategoriesByType();

    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  // Fungsi untuk membuka modal edit transaksi
  window.editTransaction = function(id) {
    const trx = state.allTransactions.find(t => t.id === id);
    if (!trx) {
      tgApp.showToast('Transaksi tidak ditemukan', 'danger');
      return;
    }

    editingTransactionId = id;
    document.getElementById('transaction-id').value = trx.id;
    document.querySelector('#transactionModal .modal-title').textContent = 'Edit Transaksi';

    // Isi form dengan data transaksi
    document.getElementById('type-select').value = trx.type;
    document.getElementById('amount-input').value = trx.amount;
    document.getElementById('date-input').value = trx.transaction_date;
    document.getElementById('desc-input').value = trx.description || '';

    // Populate dompet dan disable (readonly)
    const walletSelect = document.getElementById('wallet-select');
    populateWalletSelect(trx.wallet_id);
    walletSelect.disabled = true;

    // Filter kategori dan langsung set nilai setelah opsi dibuat
    filterCategoriesByType(() => {
      const categorySelect = document.getElementById('category-select');
      if (categorySelect) {
        categorySelect.value = trx.category_id;
      }
    });

    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  // Fungsi filter kategori dengan callback opsional setelah render
  function filterCategoriesByType(callback = null) {
    const typeSelect = document.getElementById('type-select');
    const categorySelect = document.getElementById('category-select');
    if (!typeSelect || !categorySelect) return;

    const selectedType = typeSelect.value;
    // Simpan nilai yang mungkin sudah dipilih (tidak relevan untuk mode edit)
    // const currentCategoryId = categorySelect.value;

    const filtered = state.categories.filter(cat => {
    if (selectedType === 'income') {
    return cat.type === 'income' || cat.type === 'both';
    } else if (selectedType === 'expense') {
    return cat.type === 'expense' || cat.type === 'both';
    }
    return true;
    });

    // Render ulang opsi
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';
    filtered.forEach(cat => {
    const option = document.createElement('option');
    option.value = cat.id;
    option.textContent = cat.name;
    categorySelect.appendChild(option);
    });

    // Panggil callback setelah opsi selesai ditambahkan
    if (callback) {
      callback();
    }
  }

  // Helper: isi dropdown dompet
  function populateWalletSelect(selectedId = null) {
    const walletSelect = document.getElementById('wallet-select');
    walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    if (selectedId && wallet.id == selectedId) {
    option.selected = true;
    }
    walletSelect.appendChild(option);
    });
  }

  // Simpan transaksi (tambah/edit)
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

    if (!isEdit) delete data.id;

    try {
      tgApp.showLoading('Menyimpan...');
      const url = isEdit ? `${BASE_URL}/api/fintech/transactions/${id}`: `${BASE_URL}/api/fintech/transactions`;
      const method = isEdit ? 'PUT': 'POST';

      await tgApp.fetchWithAuth(url, {
      method: method,
      body: JSON.stringify(data)
      });

      await loadWallets();
      await loadAllTransactions();

      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Transaksi diperbarui' : 'Transaksi berhasil');
      bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();

      // Reset state
      editingTransactionId = null;
      document.getElementById('wallet-select').disabled = false;
      document.querySelector('#transactionModal .modal-title').textContent = 'Tambah Transaksi';
      document.getElementById('transactionForm').reset();

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

  // Reset modal setelah ditutup
  document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('transactionModal');
  modal.addEventListener('hidden.bs.modal', () => {
  document.getElementById('transactionForm').reset();
  document.getElementById('transaction-id').value = '';
  document.getElementById('wallet-select').disabled = false;
  document.querySelector('#transactionModal .modal-title').textContent = 'Tambah Transaksi';
  editingTransactionId = null;
  });
  });
</script>