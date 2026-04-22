<div class="modal fade" id="uploadStatementModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Upload Statement Bank</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="uploadStatementForm">
          <div class="mb-3">
            <label class="form-label">Dompet Tujuan <span class="text-danger">*</span></label>
            <select class="form-select" name="wallet_id" required>
              <option value="">Pilih Dompet</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">File Statement <span class="text-danger">*</span></label>
            <input type="file" class="form-control" name="file" required>
            <small class="text-muted">PDF, Excel, CSV (maks 10MB)</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Password PDF (Opsional)</label>
            <input type="password" class="form-control" name="password" placeholder="Kosongkan jika tidak ada">
          </div>
        </form>
        <div id="upload-progress" class="d-none">
          <div class="progress">
            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 100%">
              Memproses...
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="uploadStatement()">Upload & Proses</button>
      </div>
    </div>
  </div>
</div>

<script>
  let currentStatementId = null;
  let previewTransactions = [];
  let previewCategories = [];

  async function renderPreviewStatementPage(statementId) {
    currentStatementId = statementId;
    state.currentPage = 'statement-preview';

    const html = `
    <div class="d-flex align-items-center">
    <button class="btn btn-link me-2" onclick="navigateTo('statements')">
    <i class="bi bi-arrow-left"></i>
    </button>
    <h5 class="mb-0">Preview Statement</h5>
    </div>
    <div id="preview-content" class="text-center py-5">
    <div class="spinner-border text-primary" role="status"></div>
    <p class="mt-2">Memuat data...</p>
    </div>
    <div id="preview-actions" class="position-fixed bottom-0 start-0 w-75 bg-transparent border-top p-3 d-none" style="padding-bottom: 70px !important;">
    <button class="btn btn-primary opacity-50 w-100" onclick="importSelectedTransactions()">
    <i class="bi bi-check-lg me-2"></i>Import Terpilih (<span id="selected-count">0</span>)
    </button>
    </div>
    `;
    document.getElementById('main-content').innerHTML = html;
    document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));

    await loadPreviewData();
  }

  async function loadPreviewData() {
    try {
      const res = await tgApp.fetchWithAuth(BASE_URL + `/api/fintech/statements/${currentStatementId}/preview`);
      const data = res.data;

      previewTransactions = data.transactions;
      previewCategories = data.categories;

      renderPreviewContent(data);
    } catch (error) {
      document.getElementById('preview-content').innerHTML = `
      <div class="alert alert-danger">Gagal memuat preview: ${error.message}</div>
      `;
    }
  }

  function renderPreviewContent(data) {
    const container = document.getElementById('preview-content');

    if (previewTransactions.length === 0) {
      container.innerHTML = '<p class="text-muted text-center py-4">Semua transaksi sudah diimpor.</p>';
      return;
    }

    let html = `
    <div class="mb-3">
    <div class="d-flex justify-content-between align-items-center">
    <div>
    <small class="text-muted">Dompet Tujuan:</small>
    <strong>${data.wallet.name}</strong>
    </div>
    <div>
    <button class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll()">
    <i class="bi bi-check-all me-1"></i>Pilih Semua
    </button>
    </div>
    </div>
    </div>
    <div class="list-group" id="preview-transaction-list">
    `;

    previewTransactions.forEach((trx, index) => {
    const amountClass = trx.type === 'credit' ? 'text-success' : 'text-danger';
    const typeLabel = trx.type === 'credit' ? 'Masuk' : 'Keluar';

    html += `
    <div class="list-group-item" id="trx-${trx.id}">
    <div class="d-flex align-items-start" style="overflow-x: scroll;">
    <div class="form-check me-3 mt-1">
    <input class="form-check-input transaction-checkbox" type="checkbox"
    value="${trx.id}" id="chk-${trx.id}"
    ${trx.category ? 'checked' : ''}
    onchange="updateSelectedCount()">
    </div>
    <div class="flex-grow-1">
    <div class="d-flex justify-content-between align-items-start">
    <div class="me-2" style="max-width: 70%;">
    <div class="fw-semibold text-truncate" title="${trx.description}">${trx.description}</div>
    <small class="text-muted">${formatDate(trx.date)}</small>
    </div>
    <span class="${amountClass} fw-bold">${trx.formatted_amount}</span>
    </div>
    <div class="mt-2 d-flex align-items-center flex-wrap">
    <span class="badge bg-secondary me-2">${typeLabel}</span>
    <select class="form-select form-select-sm category-select"
    style="width: auto; min-width: 150px;"
    data-transaction-id="${trx.id}"
    onchange="updateTransactionCategory(${trx.id}, this.value)">
    <option value="">Pilih Kategori</option>
    ${previewCategories.map(cat => `
    <option value="${cat.id}" ${trx.category?.id === cat.id ? 'selected' : ''}>
    ${cat.name}
    </option>
    `).join('')}
    </select>
    </div>
    </div>
    </div>
    </div>
    `;
    });

    html += `</div>`;
    container.innerHTML = html;

    document.getElementById('preview-actions').classList.remove('d-none');
    updateSelectedCount();
  }

  function toggleSelectAll() {
    const checkboxes = document.querySelectorAll('.transaction-checkbox');
    const allChecked = Array.from(checkboxes).every(cb => cb.checked);

    checkboxes.forEach(cb => cb.checked = !allChecked);
    updateSelectedCount();
  }

  function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.transaction-checkbox');
    const selected = Array.from(checkboxes).filter(cb => cb.checked).length;
    document.getElementById('selected-count').textContent = selected;
  }

  async function updateTransactionCategory(transactionId, categoryId) {
    if (!categoryId) return;

    try {
      await tgApp.fetchWithAuth(
      BASE_URL + `/api/fintech/statements/transactions/${transactionId}/category`,
      {
      method: 'PUT',
      body: JSON.stringify({ category_id: categoryId })
      }
      );

      // Update local state
      const trx = previewTransactions.find(t => t.id === transactionId);
      if (trx) {
        const cat = previewCategories.find(c => c.id == categoryId);
        trx.category = cat;
      }

      // Check checkbox if category selected
      document.getElementById(`chk-${transactionId}`).checked = true;
      updateSelectedCount();

      tgApp.showToast('Kategori diperbarui', 'success');
    } catch (error) {
      tgApp.showToast('Gagal mengupdate kategori', 'danger');
    }
  }

  async function importSelectedTransactions() {
    const checkboxes = document.querySelectorAll('.transaction-checkbox:checked');
    const selectedIds = Array.from(checkboxes).map(cb => cb.value);

    if (selectedIds.length === 0) {
      tgApp.showToast('Pilih minimal satu transaksi', 'warning');
      return;
    }

    // Validasi: semua transaksi yang dipilih harus memiliki kategori
    const missingCategory = selectedIds.some(id => {
    const trx = previewTransactions.find(t => t.id == id);
    return !trx?.category;
    });

    if (missingCategory) {
      tgApp.showToast('Semua transaksi yang dipilih harus memiliki kategori', 'warning');
      return;
    }

    if (!confirm(`Import ${selectedIds.length} transaksi ke dompet?`)) return;

    try {
      tgApp.showLoading('Mengimpor...');

      const res = await tgApp.fetchWithAuth(
      BASE_URL + `/api/fintech/statements/${currentStatementId}/import`,
      {
      method: 'POST',
      body: JSON.stringify({ transaction_ids: selectedIds })
      }
      );

      tgApp.hideLoading();
      let message = res.message;
      if (res.data.skipped > 0) {
        message += '\n\nDilewati:\n' + res.data.skipped_reasons.join('\n');
      }
      tgApp.showToast(message, res.data.skipped > 0 ? 'warning' : 'success');

      // Refresh preview
      await loadPreviewData();
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal mengimpor', 'danger');
    }
  }

  function showUploadStatementModal() {
    // Reset form
    const form = document.getElementById('uploadStatementForm');
    if (form) form.reset();

    // Isi dropdown dompet
    const walletSelect = document.querySelector('select[name="wallet_id"]');
    if (walletSelect) {
      walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
      state.wallets.filter(w => w.is_active).forEach(wallet => {
      const option = document.createElement('option');
      option.value = wallet.id;
      option.textContent = `${wallet.name} (${wallet.formatted_balance})`;
      walletSelect.appendChild(option);
      });

      const defaultWalletId = state.userSettings?.default_wallet_id;
      if (defaultWalletId) {
        walletSelect.value = defaultWalletId;
      }
    }

    document.getElementById('upload-progress')?.classList.add('d-none');
    new bootstrap.Modal(document.getElementById('uploadStatementModal')).show();
  }

  async function uploadStatement() {
    const form = document.getElementById('uploadStatementForm');
    const formData = new FormData(form);

    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput.files.length) {
      tgApp.showToast('Pilih file terlebih dahulu', 'danger');
      return;
    }

    const walletId = formData.get('wallet_id');
    if (!walletId) {
      tgApp.showToast('Pilih dompet tujuan', 'danger');
      return;
    }

    alert(JSON.stringify(formData) + ' ' + formData.entries() + ' ' + formData.get('wallet_id') + ' ' + formData.get('file'));
    // Tampilkan progress
    document.getElementById('upload-progress')?.classList.remove('d-none');
    const submitBtn = form.closest('.modal').querySelector('.btn-primary');
    submitBtn.disabled = true;

    try {
      const res = await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/statements/upload', {
      method: 'POST',
      body: formData
      });

      const data = await res.json();

      if (data.success) {
        tgApp.showToast(`Berhasil memproses ${data.data.transaction_count} transaksi`);
        // Tutup modal
        bootstrap.Modal.getInstance(document.getElementById('uploadStatementModal')).hide();
        // Bisa langsung buka preview atau refresh halaman
        renderPreviewStatementPage(data.data.statement_id);
      } else {
        tgApp.showToast(data.message || 'Gagal memproses statement', 'danger');
      }
    } catch (error) {
      console.error('Upload error:', error);
      tgApp.showToast('Terjadi kesalahan jaringan. ' + error.message, 'danger');
    } finally {
      document.getElementById('upload-progress')?.classList.add('d-none');
      submitBtn.disabled = false;
    }
  }
</script>