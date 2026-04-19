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
            <select class="form-select" name="type" id="transaction-type" required>
              <option value="income">Pemasukan</option>
              <option value="expense">Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori <span class="text-danger">*</span></label>
            <select class="form-select" name="category_id" id="transaction-category" required>
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
  // Simpan referensi ke fungsi asli jika diperlukan, tapi kita akan override langsung
  const originalShowAddTransactionModal = window.showAddTransactionModal;

  // Override fungsi global showAddTransactionModal
  window.showAddTransactionModal = function() {
    const form = document.getElementById('transactionForm');
    form.reset();
    document.querySelector('input[name="transaction_date"]').value = new Date().toISOString().split('T')[0];

    // Isi dropdown dompet
    const walletSelect = document.querySelector('select[name="wallet_id"]');
    walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
    if (typeof state !== 'undefined' && state.wallets) {
      state.wallets.filter(w => w.is_active).forEach(wallet => {
      const option = document.createElement('option');
      option.value = wallet.id;
      option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
      walletSelect.appendChild(option);
      });
    }

    // Isi dropdown kategori awal (akan difilter setelahnya)
    const categorySelect = document.getElementById('transaction-category');
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';
    if (typeof state !== 'undefined' && state.categories) {
      // Render semua dulu, nanti difilter
      state.categories.forEach(cat => {
      const option = document.createElement('option');
      option.value = cat.id;
      option.textContent = cat.name;
      option.dataset.type = cat.type;
      categorySelect.appendChild(option);
      });
    }

    // Pasang event listener untuk filter kategori
    const typeSelect = document.getElementById('transaction-type');

    // Hapus listener lama jika ada (hindari duplikat)
    typeSelect.removeEventListener('change', filterCategoriesByType);
    typeSelect.addEventListener('change', filterCategoriesByType);

    // Panggil filter pertama kali
    filterCategoriesByType();

    // Tampilkan modal
    new bootstrap.Modal(document.getElementById('transactionModal')).show();
  };

  // Fungsi untuk memfilter kategori berdasarkan tipe transaksi
  function filterCategoriesByType() {
    const typeSelect = document.getElementById('transaction-type');
    const categorySelect = document.getElementById('transaction-category');
    if (!typeSelect || !categorySelect) return;

    const selectedType = typeSelect.value; // 'income' atau 'expense'
    const currentCategoryId = categorySelect.value;

    // Simpan semua opsi yang ada
    const allOptions = Array.from(categorySelect.options);

    // Kosongkan select
    categorySelect.innerHTML = '<option value="">Pilih Kategori</option>';

    // Filter dan tambahkan opsi yang sesuai
    let hasSelected = false;
    allOptions.forEach(option => {
    if (option.value === '') return; // Lewati placeholder

    const catType = option.dataset.type;
    let show = false;

    if (selectedType === 'income') {
    show = (catType === 'income' || catType === 'both');
    } else if (selectedType === 'expense') {
    show = (catType === 'expense' || catType === 'both');
    }

    if (show) {
    const newOption = document.createElement('option');
    newOption.value = option.value;
    newOption.textContent = option.textContent;
    newOption.dataset.type = catType;
    if (option.value === currentCategoryId) {
    newOption.selected = true;
    hasSelected = true;
    }
    categorySelect.appendChild(newOption);
    }
    });

    // Jika kategori yang sebelumnya dipilih tidak ada di hasil filter, reset ke placeholder
    if (!hasSelected && currentCategoryId) {
      categorySelect.value = '';
    }
  }

  async function saveTransaction() {
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

      // Refresh tampilan sesuai halaman
      if (state.currentPage === 'home') {
        renderHomePage();
      } else if (state.currentPage === 'transactions') {
        renderTransactionList();
        updateTransactionStats();
      }
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
    }
  }
</script>