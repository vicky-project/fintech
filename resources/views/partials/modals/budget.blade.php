<div class="modal fade" id="budgetModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="budgetModalTitle">Tambah Budget</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="budgetForm">
          <input type="hidden" name="id" id="budget-id">
          <div class="mb-3">
            <label class="form-label">Kategori <span class="text-danger">*</span></label>
            <select class="form-select" name="category_id" id="budget-category" required>
              <option value="">Pilih Kategori</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Dompet (Opsional)</label>
            <select class="form-select" name="wallet_id" id="budget-wallet">
              <option value="">Semua Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah (Rp) <span class="text-danger">*</span></label>
            <input type="number" class="form-control" name="amount" id="budget-amount" min="1000" step="1000" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Periode <span class="text-danger">*</span></label>
            <select class="form-select" name="period_type" id="budget-period" required>
              <option value="monthly">Bulanan</option>
              <option value="yearly">Tahunan</option>
            </select>
          </div>
          <div class="mb-3" id="budget-active-group" style="display:none;">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_active" id="budget-is-active" value="1" checked>
              <label class="form-check-label" for="budget-is-active">Budget Aktif</label>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="saveBudget()">Simpan</button>
      </div>
    </div>
  </div>
</div>

<script>
  // Simpan ID budget yang sedang diedit
  let editingBudgetId = null;

  function showAddBudgetModal() {
    editingBudgetId = null;
    document.getElementById('budget-id').value = '';
    document.getElementById('budgetModalTitle').textContent = 'Tambah Budget';
    document.getElementById('budget-amount').value = '';
    document.getElementById('budget-period').value = 'monthly';
    document.getElementById('budget-active-group').style.display = 'none';
    populateBudgetCategories();
    populateBudgetWallets();
    new bootstrap.Modal(document.getElementById('budgetModal')).show();
  }

  async function showEditBudgetModal(budgetId) {
    const budget = state.budgets.find(b => b.id === budgetId);
    if (!budget) return;

    editingBudgetId = budgetId;
    document.getElementById('budget-id').value = budget.id;
    document.getElementById('budgetModalTitle').textContent = 'Edit Budget';
    document.getElementById('budget-amount').value = budget.amount;
    document.getElementById('budget-period').value = budget.period_type;
    document.getElementById('budget-active-group').style.display = 'block';
    document.getElementById('budget-is-active').checked = true; // asumsi semua budget aktif

    populateBudgetCategories(budget.category?.id);
    populateBudgetWallets(budget.wallet?.id);
    new bootstrap.Modal(document.getElementById('budgetModal')).show();
  }

  function populateBudgetCategories(selectedId = null) {
    const select = document.getElementById('budget-category');
    select.innerHTML = '<option value="">Pilih Kategori</option>';
    state.categories.filter(c => c.type === 'expense' || c.type === 'both').forEach(c => {
    const option = document.createElement('option');
    option.value = c.id;
    option.textContent = c.name;
    if (c.id == selectedId) option.selected = true;
    select.appendChild(option);
    });
  }

  function populateBudgetWallets(selectedId = null) {
    const select = document.getElementById('budget-wallet');
    select.innerHTML = '<option value="">Semua Dompet</option>';
    state.wallets.forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    if (w.id == selectedId) option.selected = true;
    select.appendChild(option);
    });
  }

  async function saveBudget() {
    const form = document.getElementById('budgetForm');
    const data = Object.fromEntries(new FormData(form));
    const id = data.id;
    const isEdit = !!id;

    if (!data.category_id || !data.amount) {
      tgApp.showToast('Harap isi semua field wajib', 'danger');
      return;
    }

    if (isEdit) {
      delete data.category_id;
      delete data.wallet_id;
      delete data.period_type;
      data.is_active = data.is_active === '1';
    } else {
      delete data.id;
      delete data.is_active;
    }

    const url = isEdit ? `/api/fintech/budgets/${id}`: `/api/fintech/budgets`;

    try {
      tgApp.showLoading('Menyimpan...');
      if (isEdit) {
        await api.put(url, data);
      } else {
        await api.post(url, data);
      }

      await refreshBudgetList();
      tgApp.hideLoading();
      tgApp.showToast(isEdit ? 'Budget diupdate': 'Budget dibuat');
      bootstrap.Modal.getInstance(document.getElementById('budgetModal')).hide();
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
    }
  }

  async function deleteBudget(id) {
    if (!confirm('Hapus budget ini?')) return;
    try {
      await api.delete(`/api/fintech/budgets/${id}`});
      await refreshBudgetList();
      tgApp.showToast('Budget dihapus');
    } catch (error) {
      tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
    }
  }
</script>