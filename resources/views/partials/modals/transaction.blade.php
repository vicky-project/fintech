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
            <select class="form-select" name="wallet_id" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe <span class="text-danger">*</span></label>
            <select class="form-select" name="type" required>
              <option value="income">Pemasukan</option>
              <option value="expense">Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori <span class="text-danger">*</span></label>
            <select class="form-select" name="category_id" required>
              <option value="">Pilih Kategori</option>
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
            <label class="form-label">Deskripsi</label>
            <input type="text" class="form-control" name="description">
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
  // Fungsi untuk membuka modal dan mengisi data awal
  window.showAddTransactionModal = function() {
    const form = document.getElementById('transactionForm');
    form.reset();
    document.querySelector('input[name="transaction_date"]').value = new Date().toISOString().split('T')[0];

    // Isi dropdown dompet
    const walletSelect = document.querySelector('select[name="wallet_id"]');
    walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    walletSelect.appendChild(option);
    });

    // Isi dropdown kategori awal (akan difilter setelah tipe dipilih)
    const categorySelect = document.querySelector('select[name="category_id"]');
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';

    // Pasang event listener untuk filter kategori
    const typeSelect = document.querySelector('select[name="type"]');
    // Hapus listener lama jika ada
    typeSelect.removeEventListener('change', filterCategoriesByType);
    typeSelect.addEventListener('change', filterCategoriesByType);

    // Panggil filter untuk pertama kali
    filterCategoriesByType();

    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  // Fungsi untuk memfilter kategori berdasarkan tipe transaksi yang dipilih
  function filterCategoriesByType() {
    const typeSelect = document.querySelector('select[name="type"]');
    const categorySelect = document.querySelector('select[name="category_id"]');
    if (!typeSelect || !categorySelect) return;

    const selectedType = typeSelect.value; // 'income' atau 'expense'
    const currentCategoryId = categorySelect.value;

    // Filter kategori yang sesuai
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
    if (cat.id == currentCategoryId) {
    option.selected = true;
    }
    categorySelect.appendChild(option);
    });
  }

  // Fungsi untuk menyimpan transaksi
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

    // Hapus field id jika tidak diedit
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

      // Reset editing ID
      editingTransactionId = null;
      document.getElementById('transaction-id').value = '';
      document.querySelector('#transactionModal .modal-title').textContent = 'Tambah Transaksi';

      // Refresh tampilan
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

  // Simpan ID transaksi yang sedang diedit
  let editingTransactionId = null;

  // Fungsi untuk membuka modal edit
  window.editTransaction = function(id) {
    const trx = state.allTransactions.find(t => t.id === id);
    if (!trx) {
      tgApp.showToast('Transaksi tidak ditemukan', 'danger');
      return;
    }

    editingTransactionId = id;

    // Isi form dengan data transaksi
    document.getElementById('transaction-id').value = trx.id;
    document.querySelector('select[name="wallet_id"]').value = trx.wallet_id;
    document.querySelector('select[name="type"]').value = trx.type;
    document.querySelector('input[name="amount"]').value = trx.amount;
    document.querySelector('input[name="transaction_date"]').value = trx.transaction_date;
    document.querySelector('input[name="description"]').value = trx.description || '';

    // Isi dropdown dompet (opsional, karena wallet_id sudah ada)
    const walletSelect = document.querySelector('select[name="wallet_id"]');
    walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    state.wallets.filter(w => w.is_active).forEach(wallet => {
    const option = document.createElement('option');
    option.value = wallet.id;
    option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
    if (wallet.id == trx.wallet_id) option.selected = true;
    walletSelect.appendChild(option);
    });

    // Filter kategori berdasarkan tipe
    filterCategoriesByType();

    // Set kategori yang sesuai
    setTimeout(() => {
    document.querySelector('select[name="category_id"]').value = trx.category_id;
    }, 10);

    // Ubah judul modal
    document.querySelector('#transactionModal .modal-title').textContent = 'Edit Transaksi';

    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  window.deleteTransaction = async function(id) {
    if (!confirm('Pindahkan transaksi ke tempat sampah?')) return;

    try {
      tgApp.showLoading('Menghapus...');
      await tgApp.fetchWithAuth(`${BASE_URL}/api/fintech/transactions/${id}`, {
      method: 'DELETE'
      });

      await loadWallets();
      await loadAllTransactions();

      tgApp.hideLoading();
      tgApp.showToast('Transaksi dipindahkan ke tempat sampah');

      if (state.currentPage === 'home') {
        renderHomePage();
      } else if (state.currentPage === 'transactions') {
        renderTransactionList();
        updateTransactionStats();
      }
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal menghapus transaksi', 'danger');
    }
  };

  document.addEventListener('DOMContentLoaded', () => {
  const modal = document.getElementById('transactionModal');
  modal.addEventListener('hidden.bs.modal', () => {
  document.getElementById('transactionForm').reset();
  document.getElementById('transaction-id').value = '';
  document.querySelector('#transactionModal .modal-title').textContent = 'Tambah Transaksi';
  editingTransactionId = null;
  });
  });
</script>