<div class="modal fade" id="walletModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="walletModalTitle">Tambah Dompet</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="walletForm">
          <input type="hidden" name="id" id="wallet-id">
          <div class="mb-3">
            <label class="form-label">Nama Dompet <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" id="wallet-name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Mata Uang <span class="text-danger">*</span></label>
            <select class="form-select" name="currency" id="wallet-currency" required>
              <option value="">Memuat data...</option>
            </select>
            <small class="text-muted" id="currency-hint" style="display:none;">Mata uang tidak dapat diubah.</small>
          </div>
          <div class="mb-3" id="initial-balance-group">
            <label class="form-label">Saldo Awal</label>
            <input type="number" class="form-control" name="initial_balance" step="0.01" min="0" value="0">
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi</label>
            <input type="text" class="form-control" name="description" id="wallet-description">
          </div>
          <div class="mb-3" id="is-active-group" style="display:none;">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_active" id="wallet-is-active" value="1" checked>
              <label class="form-check-label" for="wallet-is-active">Dompet Aktif</label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="saveWallet()">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
  let editingWalletId = null;

  window.showAddWalletModal = function() {
    editingWalletId = null;
    const form = document.getElementById('walletForm');
    form.reset();
    document.getElementById('wallet-id').value = '';
    document.getElementById('walletModalTitle').textContent = 'Tambah Dompet';
    document.getElementById('currency-hint').style.display = 'none';
    document.getElementById('initial-balance-group').style.display = 'block';
    document.getElementById('is-active-group').style.display = 'none';

    const currencySelect = document.getElementById('wallet-currency');
    currencySelect.disabled = false;
    const defaultCurrency = Core.state.userSettings?.default_currency || '{{ config("fintech.default_currency", "IDR") }}'
    Core.populateSelectWithCurrencies(currencySelect, defaultCurrency);

    new bootstrap.Modal(document.getElementById('walletModal')).show();
  }

  window.editWallet = function(id) {
    const wallet = Core.state.wallets.find(w => w.id == id);
    alert(wallet)
    if (!wallet) return;

    editingWalletId = id;
    document.getElementById('wallet-id').value = wallet.id;
    document.getElementById('wallet-name').value = wallet.name;
    document.getElementById('wallet-description').value = wallet.description || '';
    document.getElementById('walletModalTitle').textContent = 'Edit Dompet';
    document.getElementById('currency-hint').style.display = 'block';
    document.getElementById('initial-balance-group').style.display = 'none';
    document.getElementById('is-active-group').style.display = 'block';
    document.getElementById('wallet-is-active').checked = wallet.is_active;

    const currencySelect = document.getElementById('wallet-currency');
    currencySelect.disabled = true;
    Core.populateSelectWithCurrencies(currencySelect, wallet.currency.code);

    new bootstrap.Modal(document.getElementById('walletModal')).show();
  };

  async function saveWallet() {
    const form = document.getElementById('walletForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    const id = data.id;
    const isEdit = !!id;

    if (isEdit) {
      delete data.initial_balance;
      delete data.currency;
      data.is_active = data.is_active === '1';
    } else {
      delete data.id;
      delete data.is_active;
    }

    if (!isEdit && Core.state.wallets.some(w => w.name.toLowerCase() === data.name.toLowerCase())) {
      tgApp.showToast('Nama dompet sudah digunakan', 'warning');
      return;
    }

    try {
      tgApp.showLoading('Menyimpan...');
      const url = isEdit ? `/api/fintech/wallets/${id}`: `/api/fintech/wallets`;

      if (isEdit) {
        await Core.api.put(url, data );
      } else {
        await Core.api.post(url, data);
      }

      await Core.loadWallets();
      await Core.loadHomeSummary();

      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Dompet diperbarui' : 'Dompet dibuat');
      bootstrap.Modal.getInstance(document.getElementById('walletModal')).hide();

      if (Core.state.currentPage === 'wallets') {
        Core.navigateTo('wallets');
      } else if (Core.state.currentPage === 'home') {
        Core.navigateTo('home');
      }
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
    }
  }
</script>