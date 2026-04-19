<div class="modal fade" id="transactionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Tambah Transaksi</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="transactionForm">
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

    if (!data.wallet_id || !data.category_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }

    try {
      tgApp.showLoading('Menyimpan...');
      await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/transactions', {
      method: 'POST',
      body: JSON.stringify(data)
      });

      await loadWallets();
      await loadAllTransactions();

      tgApp.hideLoading();
      tgApp.showToast('Transaksi berhasil');
      bootstrap.Modal.getInstance(document.getElementById('transactionModal')).hide();

      // Refresh tampilan sesuai halaman aktif
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