<div class="modal fade" id="suggestCategoryModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Usulkan Kategori Baru</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">
          Kategori yang Anda usulkan akan ditinjau oleh admin sebelum ditambahkan ke sistem.
        </p>
        <form id="suggestCategoryForm">
          <div class="mb-3">
            <label class="form-label">Nama Kategori <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe <span class="text-danger">*</span></label>
            <select class="form-select" name="type" required>
              <option value="income">Pemasukan</option>
              <option value="expense">Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Catatan Tambahan</label>
            <textarea class="form-control" name="notes" rows="2"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" onclick="submitCategorySuggestion()">Kirim Usulan</button>
      </div>
    </div>
  </div>
</div>

<script>
  function showSuggestCategoryModal() {
    document.getElementById('suggestCategoryForm').reset();
    new bootstrap.Modal(document.getElementById('suggestCategoryModal')).show();
  }

  async function submitCategorySuggestion() {
    const form = document.getElementById('suggestCategoryForm');
    const formData = new FormData(form);
    const data = Object.fromEntries(formData.entries());
    if (!data.name || !data.type) {
      tgApp.showToast('Nama dan tipe wajib diisi', 'danger');
      return;
    }
    try {
      tgApp.showLoading('Mengirim...');
      await tgApp.fetchWithAuth(BASE_URL + '/api/fintech/category-suggestions', {
      method: 'POST',
      body: JSON.stringify(data)
      });
      tgApp.hideLoading();
      tgApp.showToast('Usulan kategori berhasil dikirim');
      bootstrap.Modal.getInstance(document.getElementById('suggestCategoryModal')).hide();
    } catch (error) {
      tgApp.hideLoading();
      tgApp.showToast(error.message || 'Gagal mengirim', 'danger');
    }
  }
</script>