// ==================== PAGES.JS ====================
// Seluruh definisi halaman, bergantung pada Core (state, api, helpers, dll.)

// Fungsi template list page (digunakan oleh banyak halaman)
async function renderListPage(config) {
  const {
    title,
    icon,
    filterHtml,
    listContainerId,
    paginationId,
    loadFn,
    extraHeaderButtons = ''
  } = config;
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between mb-3">
  <div class="d-flex"><i class="${icon} me-2"></i><h5>${title}</h5></div>
  <div>${extraHeaderButtons}</div>
  </div>
  ${filterHtml || ''}
  <div id="${listContainerId}"></div>
  <div id="${paginationId}" class="mt-3"></div>
  </div>`;
  document.getElementById('main-content').innerHTML = html;
  await loadFn(1);
}

// ==================== HOME ====================
async function renderHomePage() {
  if (Core.state.wallets.length > 0) {
    await Core.loadHomeSummary().catch(() => tgApp.showToast('Gagal memuat ringkasan', 'warning'));
  }

  const summary = Core.state.homeSummary;
  if (!summary) {
    document.getElementById('main-content').innerHTML = '<p class="text-center py-5">Memuat ringkasan...</p>';
    return;
  }

  const symbol = Core.getCurrencySymbol(summary.currency);
  const html = `
  <div class="container py-3">
  <div class="card bg-gradient-primary text-white mb-3" style="background: linear-gradient(135deg, #667eea, #764ba2);">
  <div class="card-body">
  <h6>Total Saldo</h6>
  <h2>${symbol} ${Core.formatNumber(summary.total_balance)}</h2>
  <small>${Core.state.wallets.length} dompet aktif</small>
  </div>
  </div>
  <div class="row g-2 mb-3">
  <div class="col-6">
  <div class="card"><div class="card-body p-3 text-center"><i class="bi bi-arrow-down-circle text-success fs-4"></i><h6 class="mb-0">${Core.formatNumberShort(summary.total_income)}</h6><small>Pemasukan</small></div></div>
  </div>
  <div class="col-6">
  <div class="card"><div class="card-body p-3 text-center"><i class="bi bi-arrow-up-circle text-danger fs-4"></i><h6 class="mb-0">${Core.formatNumberShort(summary.total_expense)}</h6><small>Pengeluaran</small></div></div>
  </div>
  </div>
  <div class="card mb-3">
  <div class="card-body">
  <h6>Pengeluaran Mingguan</h6>
  <div style="height: 180px;"><canvas id="homeChart"></canvas></div>
  </div>
  </div>
  <h6>Transaksi Terbaru</h6>
  <div id="recent-transactions"></div>
  </div>`;
  document.getElementById('main-content').innerHTML = html;
  setTimeout(() => {
    loadHomeChartFromSummary();
    renderRecentTransactionsFromSummary();
  }, 50);
}

function loadHomeChartFromSummary() {
  const ctx = document.getElementById('homeChart')?.getContext('2d');
  if (!ctx) return;
  const data = Core.state.homeSummary.weekly_expense;
  if (Core.state.chartInstances.home) Core.state.chartInstances.home.destroy();
  if (!data || data.length === 0) return;
  Core.state.chartInstances.home = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: data.map(d => d.label),
      datasets: [{
        data: data.map(d => d.value),
        backgroundColor: data.map(d => d.color)
      }]
    }
  });
}

function renderRecentTransactionsFromSummary() {
  const container = document.getElementById('recent-transactions');
  const transactions = Core.state.homeSummary.recent_transactions;
  if (!transactions.length) {
    container.innerHTML = '<p class="text-muted text-center">Belum ada transaksi</p>';
    return;
  }
  container.innerHTML = transactions.map(trx => {
    const amountClass = trx.type === 'income' ? 'text-success': 'text-danger';
    const sign = trx.type === 'income' ? '': '-';
    return `<div class="d-flex justify-content-between align-items-center border-bottom py-2">
    <div><i class="${trx.category.icon} me-2" style="color:${trx.category.color}"></i>${trx.category.name}</div>
    <span class="${amountClass}" title="${trx.formatted_amount}">${sign}${Core.formatNumberShort(trx.amount)}</span>
    </div>`;
  }).join('');
}

// ==================== TRANSACTIONS ====================
async function renderTransactionsPage() {
  const currentMonth = new Date().toISOString().slice(0, 7);
  await renderListPage( {
    title: 'Transaksi',
    icon: 'bi bi-list-ul',
    filterHtml: `
    <div class="row g-2 mb-3" id="transaction-stats"></div>
    <div class="row g-2 mb-3">
    <div class="col-4">
    <select class="form-select form-select-sm" id="filter-wallet" data-action="change-transaction-filter">
    <option value="">Semua Dompet</option>
    ${Core.state.wallets.map(w => `<option value="${w.id}" ${w.id == Core.state.filters.wallet_id ? 'selected': ''}>${w.name}</option>`).join('')}
    </select>
    </div>
    <div class="col-4">
    <select class="form-select form-select-sm" id="filter-type" data-action="change-transaction-filter">
    <option value="">Semua Tipe</option>
    <option value="income" ${Core.state.filters?.type === 'income' ? 'selected': ''}>Pemasukan</option>
    <option value="expense" ${Core.state.filters?.type === 'expense' ? 'selected': ''}>Pengeluaran</option>
    </select>
    </div>
    <div class="col-4">
    <input type="month" class="form-control form-control-sm" id="filter-month" value="${Core.state.filters.month || currentMonth}" data-action="change-transaction-filter">
    </div>
    </div>
    <div class="mb-3">
    <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-secondary flex-grow-1" data-action="reset-transaction-filter"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
    <button class="btn btn-sm btn-outline-primary flex-grow-1" data-action="apply-transaction-filter"><i class="bi bi-funnel me-1"></i>Terapkan</button>
    </div>
    </div>`,
    listContainerId: 'transaction-list',
    paginationId: 'transaction-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-warning me-1" onclick="showBulkDeleteModal()" title="Hapus Massal"><i class="bi bi-calendar-x"></i></button>
    <button class="btn btn-sm btn-outline-danger me-1" onclick="Core.navigateTo('transactionTrash')"><i class="bi bi-trash"></i></button>
    <button class="btn btn-sm btn-primary" data-action="add-transaction"><i class="bi bi-plus"></i></button>`,
    loadFn: refreshTransactionList
  });
  updateTransactionStats();
}

// Reset filter transaksi
window.resetTransactionFilter = function() {
  Core.state.filters.wallet_id = '';
  Core.state.filters.type = '';
  Core.state.filters.month = '';
  Core.state.transactionPage = 1;
  document.getElementById('filter-wallet').value = '';
  document.getElementById('filter-type').value = '';
  document.getElementById('filter-month').value = new Date().toISOString().slice(0, 7);
  refreshTransactionList();
};

// Terapkan filter transaksi
window.applyTransactionFilter = function() {
  Core.state.filters.wallet_id = document.getElementById('filter-wallet')?.value || '';
  Core.state.filters.type = document.getElementById('filter-type')?.value || '';
  Core.state.filters.month = document.getElementById('filter-month')?.value || '';
  Core.state.transactionPage = 1;
  refreshTransactionList();
};

async function refreshTransactionList(page) {
  page = page || Core.state.transactionPage || 1;
  const filters = {
    wallet_id: Core.state.filters.wallet_id,
    type: Core.state.filters.type,
    month: Core.state.filters.month
  };
  await Core.loadTransactionsPage(page, filters);
  updateTransactionStats();
  renderTransactionList();
  Core.renderPagination('transaction-pagination', Core.state.transactionPage, Core.state.transactionLastPage, refreshTransactionList);

  if (Core.state.pendingAction && Core.state.pendingAction.type === 'transaction') {
    const targetId = Core.state.pendingAction.id;
    const exists = Core.state.transactions.some(t => t.id == targetId);
    if (exists) {
      setTimeout(() => editTransaction(targetId), 100);
    } else {
      loadAndEditTransaction(targetId);
    }
    Core.state.pendingAction = null;
  }
}

function updateTransactionStats() {
  const summary = Core.state.transactionSummary;
  const symbol = Core.getCurrencySymbol(Core.state.userSettings?.default_currency || 'IDR');
  document.getElementById('transaction-stats').innerHTML = `
  <div class="col-4"><div class="card p-2 text-center"><small>Total</small><strong>${summary.total}</strong></div></div>
  <div class="col-4"><div class="card p-2 text-center text-success"><small>Masuk</small><strong>${symbol}${Core.formatNumberShort(summary.income)}</strong></div></div>
  <div class="col-4"><div class="card p-2 text-center text-danger"><small>Keluar</small><strong>${symbol}${Core.formatNumberShort(summary.expense)}</strong></div></div>`;
}

function renderTransactionList() {
  const filtered = Core.state.transactions;
  const container = document.getElementById('transaction-list');
  if (filtered.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Tidak ada transaksi</p>';
    return;
  }
  container.innerHTML = filtered.map(trx => {
    const amountClass = trx.type === 'income' ? 'text-success': 'text-danger';
    const sign = trx.type === 'income' ? '': '-';
    return `
    <div class="card mb-2" style="overflow: hidden;">
    <div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1 me-2" onclick="showTransactionDetailModal(${trx.id})" style="cursor: pointer; min-width: 0;">
    <div class="d-flex align-items-center">
    <i class="${trx.category.icon} me-2" style="color:${trx.category.color}; flex-shrink: 0;"></i>
    <div style="min-width: 0;">
    <div class="fw-semibold text-truncate">${trx.category.name}</div>
    <small class="text-muted text-truncate d-block">${trx.wallet.name} · ${Core.formatDate(trx.transaction_date)}</small>
    </div>
    </div>
    ${trx.description ? `<div class="mt-1" style="word-break: break-word; overflow-wrap: anywhere;"><small class="text-muted">${trx.description}</small></div>`: ''}
    </div>
    <div class="d-flex align-items-center flex-shrink-0">
    <span class="${amountClass} fw-bold me-2" title="${trx.formatted_amount}">${sign}${Core.formatNumberShort(trx.amount)}</span>
    <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><button class="dropdown-item" data-action="edit-transaction" data-id="${trx.id}"><i class="bi bi-pencil me-2"></i>Edit</button></li>
    <li><button class="dropdown-item text-danger" data-action="delete-transaction" data-id="${trx.id}"><i class="bi bi-trash me-2"></i>Hapus</button></li>
    </ul>
    </div>
    </div>
    </div>
    </div>
    </div>`;
  }).join('');
}

// Detail transaksi modal (singkat)
window.showTransactionDetailModal = function(id) {
  let trx = Core.state.transactions.find(t => t.id === id);
  if (!trx) {
    tgApp.showToast('Detail transaksi tidak ditemukan.', 'danger');
    return;
  }

  const body = document.getElementById('transactionDetailBody');
  const typeLabel = trx.type === 'income' ? 'Pemasukan': 'Pengeluaran';
  const amountClass = trx.type === 'income' ? 'text-success': 'text-danger';
  const sign = trx.type === 'income' ? '': '-';
  body.innerHTML = `
  <div class="text-center mb-3">
  <i class="${trx.category.icon} fs-1" style="color: ${trx.category.color}"></i>
  <h5 class="mt-2">${trx.category.name}</h5>
  <span class="badge bg-secondary">${typeLabel}</span>
  </div>
  <table class="table table-sm">
  <tr><th>Jumlah</th><td class="${amountClass} fw-bold">${sign}${trx.formatted_amount}</td></tr>
  <tr><th>Dompet</th><td>${trx.wallet.name}</td></tr>
  <tr><th>Tanggal</th><td>${Core.formatDateFull(trx.transaction_date)}</td></tr>
  <tr><th>Deskripsi</th><td>${trx.description || '-'}</td></tr>
  </table>`;
  new bootstrap.Modal(document.getElementById('transactionDetailModal')).show();
};

async function deleteTransaction(id) {
  if (!confirm('Pindahkan transaksi ke tempat sampah?')) return;
  tgApp.showLoading('Menghapus...');
  await Core.api.delete(`/api/fintech/transactions/${id}`);
  await Core.loadWallets();
  await Core.loadHomeSummary();
  tgApp.hideLoading();
  tgApp.showToast('Transaksi dipindahkan ke tempat sampah');
  if (Core.state.currentPage === 'transactions') {
    await refreshTransactionList();
  } else if (Core.state.currentPage === 'home') {
    renderHomePage();
  }
}

function showBulkDeleteModal() {
  // Isi dropdown dompet
  const walletSelect = document.getElementById('bulk-wallet');
  walletSelect.innerHTML = '<option value="">Pilih Dompet</option>';
  Core.state.wallets.filter(w => w.is_active).forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    walletSelect.appendChild(option);
  });

  const defaultWallet = Core.state.userSettings?.default_wallet_id;
  if (defaultWallet) walletSelect.value = defaultWallet;

  // Set default bulan ke bulan ini
  const today = new Date();
  const month = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`;
  document.getElementById('bulk-month').value = month;

  new bootstrap.Modal(document.getElementById('bulkDeleteModal')).show();
}

async function executeBulkDelete() {
  const walletSelect = document.getElementById('bulk-wallet');
  const walletId = walletSelect.value;
  const month = document.getElementById('bulk-month').value;

  if (!walletId) {
    tgApp.showToast('Pilih dompet terlebih dahulu', 'warning');
    return;
  }
  if (!month) {
    tgApp.showToast('Pilih bulan terlebih dahulu', 'warning');
    return;
  }

  const walletName = walletSelect.options[walletSelect.selectedIndex]?.text || 'dompet';
  if (!confirm(`Hapus semua transaksi di dompet "${walletName}" pada bulan ${month}? Tindakan ini dapat dibatalkan di Tempat Sampah.`)) return;

  try {
    tgApp.showLoading('Menghapus...');
    const res = await Core.api.post('/api/fintech/transactions/bulk-destroy', {
      wallet_id: walletId,
      month
    });

    tgApp.hideLoading();
    tgApp.showToast(res.message,
      res.success ? 'success': 'warning');

    if (res.success) {
      bootstrap.Modal.getInstance(document.getElementById('bulkDeleteModal')).hide();
      await Core.loadWallets();
      await Core.loadHomeSummary();
      if (Core.state.currentPage === 'transactions') {
        Core.navigateTo('transactions');
      }
    }
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

async function loadAndEditTransaction(id) {
  const res = await loadTransactionItem(id);
  if (res.success && res.data) {
    Core.state.transactions.push(res.data);
    setTimeout(() => editTransaction(id), 50);
  } else {
    tgApp.showToast('Transaksi tidak ditemukan', 'danger');
  }
}

async function loadTransactionItem(id) {
  tgApp.showLoading('Mengambil data transaksi...');
  const res = await Core.api.get(`/api/fintech/transactions/${id}`);
  tgApp.hideLoading();
  return res;
}

// ==================== TRANSFERS ====================
async function renderTransfersPage() {
  await renderListPage( {
    title: 'Transfer',
    icon: 'bi bi-arrow-left-right',
    filterHtml: `
    <div class="mb-3">
    <select class="form-select form-select-sm" id="transfer-wallet-filter" data-action="apply-transfer-filter">
    <option value="">Semua Dompet</option>
    ${Core.state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
    </select>
    </div>`,
    listContainerId: 'transfer-list',
    paginationId: 'transfer-pagination',
    extraHeaderButtons: `
    <button class="btn btn-sm btn-outline-danger me-1" data-action="navigate-to-transfer-trash"><i class="bi bi-trash"></i></button>
    <button class="btn btn-sm btn-primary" data-action="add-transfer"><i class="bi bi-plus"></i></button>`,
    loadFn: refreshTransferList
  });
}

window.applyTransferFilter = function() {
  Core.state.transferPage = 1;
  refreshTransferList();
};

async function refreshTransferList(page = 1) {
  const walletId = document.getElementById('transfer-wallet-filter')?.value || '';
  await Core.loadTransfersPage(page, walletId);
  renderTransferList(Core.state.transfers);
  Core.renderPagination('transfer-pagination', Core.state.transferPage, Core.state.transferLastPage, refreshTransferList);
  if (Core.state.pendingAction && Core.state.pendingAction.type === 'transfer') {
    const targetId = Core.state.pendingAction.id;
    const exists = Core.state.transfers.some(t => t.id == targetId);
    if (exists) {
      setTimeout(() => editTransfer(targetId), 100);
    } else {
      loadAndEditTransfer(targetId);
    }
    Core.state.pendingAction = null;
  }
}

function renderTransferList(transfers) {
  const container = document.getElementById('transfer-list');
  if (!transfers.length) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada transfer</p>';
    return;
  }
  container.innerHTML = transfers.map(t => `
    <div class="card mb-2">
    <div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1" data-action="edit-transer" data-id="${t.id}">
    <div class="d-flex align-items-center mb-1">
    <i class="bi bi-arrow-right me-2 text-primary"></i>
    <span>${t.from_wallet.name} → ${t.to_wallet.name}</span>
    </div>
    <div class="text-primary fw-bold mb-1" title="${t.formatted_amount}">↔ ${Core.formatNumberShort(t.amount)}</div>
    <small class="text-muted">${Core.formatDate(t.transfer_date)}</small>
    ${t.description ? `<div class="small text-muted mt-1">${t.description}</div>`: ''}
    </div>
    <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="#" data="edit-transer" data-id="${t.id}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
    <li><a class="dropdown-item text-danger" href="#" data-action="delete-transfer" data-id="${t.id}"><i class="bi bi-trash me-2"></i>Hapus</a></li>
    </ul>
    </div>
    </div>
    </div>
    </div>`).join('');
}

async function deleteTransfer(id) {
  if (!confirm('Pindahkan transfer ke tempat sampah?')) return;
  tgApp.showLoading('Menghapus...');
  await Core.api.delete(`/api/fintech/transfers/${id}`);
  await Core.loadWallets();
  await Core.loadHomeSummary();
  tgApp.hideLoading();
  tgApp.showToast('Transfer dipindahkan ke tempat sampah');
  if (Core.state.currentPage === 'transfers') {
    await refreshTransferList();
  } else if (Core.state.currentPage === 'home') {
    renderHomePage();
  }
}

async function loadAndEditTransfer(id) {
  tgApp.showLoading('Mengambil data transfer...');
  const res = await Core.api.get(`/api/fintech/transfers/${id}`);
  if (res.success && res.data) {
    Core.state.transfers.push(res.data);
    tgApp.hideLoading();
    setTimeout(() => editTransfer(id), 50);
  } else {
    tgApp.hideLoading();
    tgApp.showToast('Transfer tidak ditemukan', 'danger');
  }
}

// ==================== WALLETS ====================
function renderWalletsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between mb-3">
  <div class="d-flex"><i class="bi bi-wallet2 me-2"></i><h5>Dompet Saya</h5></div>
  <button class="btn btn-sm btn-primary" data-action="add-wallet"><i class="bi bi-plus"></i></button>
  </div>
  <div id="wallet-list"></div>
  </div>`;
  document.getElementById('main-content').innerHTML = html;
  renderWalletsList();
}

function renderWalletsList() {
  const container = document.getElementById('wallet-list');
  if (!container) return;
  container.innerHTML = Core.state.wallets.map(w => `
    <div class="card mb-2" data-action="edit-wallet" data-id="${w.id}">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div><i class="bi bi-wallet2 me-2"></i>${w.name}</div>
    <strong>${w.formatted_balance}</strong>
    </div>
    <small class="text-muted">${w.description || ''}</small>
    </div>
    </div>`).join('');
}

// ==================== REPORTS ====================
function renderReportsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Laporan Keuangan</h5>
  <button class="btn btn-sm btn-outline-primary" onclick="showReportFilterModal()">
  <i class="bi bi-funnel"></i> Filter
  </button>
  </div>
  <div id="report-period-indicator" class="mb-3 small text-muted"></div>
  <div style="height: 250px;">
  <canvas id="reportBarChart"></canvas>
  </div>
  <div id="trend-summary" class="mt-3"></div>
  <hr class="my-4">
  <div class="d-flex justify-content-between align-items-center mb-2">
  <h6>Distribusi Kategori</h6>
  <div class="btn-group btn-group-sm" role="group">
  <button type="button" class="btn btn-outline-danger ${Core.state.categoryChartType === 'expense' ? 'active': ''}" data-cat-type="expense" data-action="switch-category-type" data-cat-type="expense">Pengeluaran</button>
  <button type="button" class="btn btn-outline-success ${Core.state.categoryChartType === 'income' ? 'active': ''}" data-cat-type="income" data-action="switch-category-type" data-cat-type="income">Pemasukan</button>
  </div>
  </div>
  <div style="height: 350px;">
  <canvas id="categoryChart"></canvas>
  </div>
  <div id="category-total" class="text-center mt-2 small text-muted"></div>
  <div class="mt-4">
  <h6>Detail per Tahun</h6>
  <div id="category-table-container" class="table-responsive" style="max-height: 400px; overflow-y: auto;">
  <div class="text-center py-3">
  <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
  <span class="ms-2">Memuat tabel...</span>
  </div>
  </div>
  </div>
  </div>`;
  document.getElementById('main-content').innerHTML = html;
  setTimeout(() => {
    updateReportPeriodIndicator();
    loadReportCharts();
    loadCategoryChart();
    loadCategoryTable();
  }, 50);
}

function showReportFilterModal() {
  // Isi dropdown dompet
  const walletSelect = document.getElementById('filter-wallet');
  if (!walletSelect) return;
  walletSelect.innerHTML = '<option value="">Semua Dompet</option>';
  Core.state.wallets.forEach(w => {
    const option = document.createElement('option');
    option.value = w.id;
    option.textContent = w.name;
    if (w.id == Core.state.reportFilter.wallet_id) option.selected = true;
    walletSelect.appendChild(option);
  });

  // Set tipe periode
  const periodTypeSelect = document.getElementById('filter-period-type');
  periodTypeSelect.value = Core.state.reportFilter.periodType;

  // Render input detail periode
  renderPeriodDetailInputs();

  // Event saat tipe periode berubah
  periodTypeSelect.setAttribute('data-action',
    'render-period-detail-inputs');

  new bootstrap.Modal(document.getElementById('reportFilterModal')).show();
}
function renderPeriodDetailInputs() {
  const type = document.getElementById('filter-period-type').value;
  const container = document.getElementById('filter-period-detail');
  const filter = Core.state.reportFilter;
  const currentYear = new Date().getFullYear();
  const currentMonth = new Date().toISOString().slice(0,
    7);

  if (type === 'all_years') {
    container.innerHTML = `<p class="text-muted small">Menampilkan total per tahun untuk semua tahun yang memiliki data.</p>`;
  } else if (type === 'monthly') {
    container.innerHTML = `
    <label class="form-label">Bulan</label>
    <input type="month" class="form-control" id="filter-month" value="${filter.month || currentMonth}">
    `;
  } else if (type === 'yearly') {
    const yearOptions = [];
    for (let y = currentYear; y >= currentYear - 10; y--) {
      yearOptions.push(`<option value="${y}" ${y == (filter.year || currentYear) ? 'selected': ''}>${y}</option>`);
    }
    container.innerHTML = `
    <label class="form-label">Tahun</label>
    <select class="form-select" id="filter-year">
    ${yearOptions}
    </select>
    `;
  }
}
function applyReportFilter() {
  const walletId = document.getElementById('filter-wallet').value;
  const periodType = document.getElementById('filter-period-type').value;

  Core.state.reportFilter.wallet_id = walletId;
  Core.state.reportFilter.periodType = periodType;

  if (periodType === 'all_years') {
    Core.state.reportFilter.date = null;
    Core.state.reportFilter.month = null;
    Core.state.reportFilter.year = null;
  } else if (periodType === 'monthly') {
    Core.state.reportFilter.month = document.getElementById('filter-month').value;
    Core.state.reportFilter.date = null;
    Core.state.reportFilter.year = null;
  } else if (periodType === 'yearly') {
    Core.state.reportFilter.year = document.getElementById('filter-year').value;
    Core.state.reportFilter.date = null;
    Core.state.reportFilter.month = null;
  }

  bootstrap.Modal.getInstance(document.getElementById('reportFilterModal')).hide();
  updateReportPeriodIndicator();
  loadReportCharts();
  loadCategoryChart();
}
async function loadReportCharts() {
  try {
    const filter = Core.state.reportFilter;
    let url = `/api/fintech/reports/${filter.periodType}`;
    const params = new URLSearchParams();

    if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);

    if (filter.periodType === 'monthly' && filter.month) {
      const [year,
        month] = filter.month.split('-');
      params.append('year', parseInt(year, 10));
      params.append('month', parseInt(month, 10));
    } else if (filter.periodType === 'yearly' && filter.year) {
      params.append('year', parseInt(filter.year, 10));
    }
    // Untuk all_years tidak perlu parameter tambahan

    if (params.toString()) url += '?' + params.toString();

    const res = await Core.api.get(url);
    const data = res.data;
    const ctx = document.getElementById('reportBarChart')?.getContext('2d');
    if (ctx) {
      if (Core.state.chartInstances.report) Core.state.chartInstances.report.destroy();
      Core.state.chartInstances.report = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: data.labels,
          datasets: [{
            label: 'Pemasukan', data: data.income, backgroundColor: '#4DB6AC'
          },
            {
              label: 'Pengeluaran', data: data.expense, backgroundColor: '#FF6384'
            }]
        }
      });
    }

    // Ringkasan
    const summaryEl = document.getElementById('trend-summary');
    if (summaryEl) {
      const totalIncome = data.income.reduce((a, b) => a + b, 0);
      const totalExpense = data.expense.reduce((a, b) => a + b, 0);
      summaryEl.innerHTML = `
      <div class="row">
      <div class="col-6">
      <div class="card text-center p-2">
      <small class="text-success">Pemasukan</small>
      <strong>${Core.formatNumber(totalIncome)}</strong>
      </div>
      </div>
      <div class="col-6">
      <div class="card text-center p-2">
      <small class="text-danger">Pengeluaran</small>
      <strong>${Core.formatNumber(totalExpense)}</strong>
      </div>
      </div>
      </div>
      `;
    }
  } catch (error) {
    tgApp.showToast('Gagal memuat laporan. ' + error.message, 'danger');
  }
}
async function loadCategoryChart() {
  const filter = Core.state.reportFilter;
  const params = new URLSearchParams();

  if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);
  params.append('period_type', filter.periodType);
  params.append('type', Core.state.categoryChartType);

  if (filter.periodType === 'monthly' && filter.month) {
    const [year,
      month] = filter.month.split('-');
    params.append('year', parseInt(year, 10));
    params.append('month', parseInt(month, 10));
  } else if (filter.periodType === 'yearly' && filter.year) {
    params.append('year', parseInt(filter.year, 10));
  }
  // all_years tidak perlu year/month

  const url = `/api/fintech/reports/category-summary?${params.toString()}`;

  try {
    const res = await Core.api.get(url);
    const data = res.data;

    const ctx = document.getElementById('categoryChart')?.getContext('2d');
    if (!ctx) return;

    if (Core.state.chartInstances.category) {
      Core.state.chartInstances.category.destroy();
    }

    if (data.values.length === 0) {
      // Tampilkan pesan kosong
      document.getElementById('category-total').innerHTML = 'Tidak ada data untuk periode ini.';
      return;
    }

    Core.state.chartInstances.category = new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: data.labels,
        datasets: [{
          data: data.values,
          backgroundColor: data.colors,
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom'
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                const value = context.raw;
                const total = context.dataset.data.reduce((a, b) => a+b, 0);
                const percentage = ((value / total) * 100).toFixed(1);
                const symbol = Core.getCurrencySymbol(data.currency);
                return `${context.label}: ${symbol} ${Core.formatNumber(value)} (${percentage}%)`;
              }
            }
          }
        }
      }
    });

    const symbol = Core.getCurrencySymbol(data.currency);
    document.getElementById('category-total').innerHTML =
    `Total ${Core.state.categoryChartType === 'expense' ? 'Pengeluaran': 'Pemasukan'}: ${symbol} ${Core.formatNumber(data.total)}`;

  } catch (error) {
    console.error('Gagal memuat kategori:', error);
  }
}
function switchCategoryType(type) {
  Core.state.categoryChartType = type;
  // Update active button
  document.querySelectorAll('[data-cat-type]').forEach(btn => {
    btn.classList.remove('active');
  });
  document.querySelector(`[data-cat-type="${type}"]`).classList.add('active');
  loadCategoryChart();
  loadCategoryTable();
}
function updateReportPeriodIndicator() {
  const filter = Core.state.reportFilter;
  const indicatorEl = document.getElementById('report-period-indicator');
  if (!indicatorEl) return;

  let text = '';
  let icon = 'bi-calendar3';

  if (filter.periodType === 'all_years') {
    text = 'Semua Tahun';
    icon = 'bi-calendar-range';
  } else if (filter.periodType === 'monthly') {
    if (filter.month) {
      const [year,
        month] = filter.month.split('-');
      const date = new Date(year, month - 1);
      const monthName = date.toLocaleDateString('id-ID', {
        month: 'long', year: 'numeric'
      });
      text = `Bulanan: ${monthName}`;
    } else {
      text = 'Bulanan (belum dipilih)';
    }
  } else if (filter.periodType === 'yearly') {
    if (filter.year) {
      text = `Tahunan: ${filter.year}`;
    } else {
      text = 'Tahunan (belum dipilih)';
    }
  }

  // Tambahkan info dompet jika ada
  if (filter.wallet_id) {
    const wallet = Core.state.wallets.find(w => w.id == filter.wallet_id);
    if (wallet) {
      text += ` · ${wallet.name}`;
    }
  }

  indicatorEl.innerHTML = `<i class="${icon} me-1"></i> ${text}`;
}
async function loadCategoryTable() {
  try {
    const filter = Core.state.reportFilter;
    const params = new URLSearchParams();
    if (filter.wallet_id) params.append('wallet_id', filter.wallet_id);
    params.append('type', Core.state.categoryChartType);

    const res = await Core.api.get('/api/fintech/reports/category-table?' + params.toString());
    const data = res.data;
    renderCategoryTable(data);
  } catch (error) {
    document.getElementById('category-table-container').innerHTML = '<p class="text-muted text-center">Gagal memuat tabel.</p>';
    tgApp.showToast(error.message || "Gagal memuat table", 'danger');
    throw error;
  }
}
function renderCategoryTable(data) {
  const container = document.getElementById('category-table-container');
  if (!container) return;

  const {
    years,
    categories,
    totals,
    currency
  } = data;
  if (categories.length === 0) {
    container.innerHTML = '<p class="text-muted text-center">Tidak ada data untuk ditampilkan.</p>';
    return;
  }

  const symbol = Core.getCurrencySymbol(currency);
  let html = `
  <table class="table table-sm table-hover">
  <thead class="table-light sticky-top">
  <tr>
  <th style="min-width: 150px;">Kategori</th>
  ${years.map(y => `<th class="text-end" style="min-width: 100px;">${y}</th>`).join('')}
  <th class="text-end" style="min-width: 110px; white-space: nowrap;">Total</th>
  </tr>
  </thead>
  <tbody>
  `;

  categories.forEach(cat => {
    let rowTotal = 0;
    html += `
    <tr>
    <td style="min-width: 150px;">
    <i class="${cat.icon} me-1" style="color:${cat.color}"></i>
    <small>${cat.name}</small>
    </td>
    ${years.map(y => {
      const val = cat.data[y] || 0;
      rowTotal += val;
      return `<td class="text-end" style="100px;">${val ? Core.formatNumberShort(val): '-'}</td>`;
    }).join('')}
    <td class="text-end fw-semibold" style="min-width: 110px; white-space: nowrap;">${symbol} ${Core.formatNumberShort(rowTotal)}</td>
    </tr>
    `;
  });

  // Baris total
  html += `
  <tr class="table-primary fw-bold">
  <td style="min-width: 150px;">Total</td>
  ${years.map(y => `<td class="text-end" style="min-width: 100px;">${symbol} ${Core.formatNumberShort(totals[y] || 0)}</td>`).join('')}
  <td class="text-end" style="min-width: 110px; white-space: nowrap;">${symbol} ${Core.formatNumberShort(Object.values(totals).reduce((a, b) => a + b, 0))}</td>
  </tr>
  </tbody>
  </table>
  `;
  container.innerHTML = html;
}

// ==================== SETTINGS, INSIGHTS, STATEMENTS, BUDGETS, NOTIFICATIONS, SEARCH, TRASH ====================
// Semua definisi selanjutnya tetap disertakan (kode asli Anda). Karena terlalu panjang, saya lampirkan secara ringkas.

// Settings
async function renderSettingsPage() {
  const settings = Core.state.userSettings || {
    default_currency: 'IDR',
    default_wallet_id: '',
    pin_enabled: false
  };
  const html = `
  <div class="container py-3">
  <div class="d-flex align-items-center mb-3">
  <i class="bi bi-gear me-2"></i>
  <h5 class="mb-0">Pengaturan</h5>
  </div>
  <form id="settingsForm">
  <div class="mb-3">
  <label class="form-label">Mata Uang Default</label>
  <select class="form-select" name="default_currency" id="setting-currency">
  <option value="">Pilih Mata Uang</option>
  </select>
  </div>
  <div class="mb-3">
  <label class="form-label">Dompet Default</label>
  <select class="form-select" name="default_wallet_id" id="setting-wallet">
  <option value="">Tidak Ada</option>
  ${Core.state.wallets.map(w => `<option value="${w.id}">${w.name}</option>`).join('')}
  </select>
  </div>
  <hr>
  <h6>Keamanan</h6>
  <div class="mb-3">
  <div class="form-check form-switch">
  <input class="form-check-input" type="checkbox" name="pin_enabled" id="pin-enabled" value="1" ${settings.pin_enabled ? 'checked': ''} data-action="toggle-pin">
  <label class="form-check-label" for="pin-enabled">Aktifkan PIN</label>
  </div>
  </div>
  <div class="mb-3" id="pin-field-group" style="display: ${settings.pin_enabled ? 'block': 'none'};">
  <label class="form-label">PIN (4-6 digit)</label>
  <input type="password" class="form-control" name="pin" id="pin-field" inputmode="numeric" pattern="[0-9]*" maxlength="6" minlength="4" placeholder="Masukkan PIN baru">
  <small class="text-muted">Kosongkan jika tidak ingin mengubah PIN.</small>
  </div>
  <button type="button" class="btn btn-primary w-100" data-action="save-settings">Simpan Pengaturan</button>
  </form>
  <hr>
  <h6>Integrasi</h6>
  <div class="mb-3">
  <div id="google-connect-area">
  <div class="d-flex justify-content-between align-items-center">
  <div>
  <i class="bi bi-google me-2"></i> Google Sheets
  <small class="text-muted d-block">Hubungkan akun Google untuk ekspor langsung ke Sheets.</small>
  </div>
  <button id="btn-connect-google" class="btn btn-outline-danger btn-sm d-none" data-action="connect-google">
  <i class="bi bi-link-45deg"></i> Hubungkan
  </button>
  <span id="google-connected-badge" class="badge bg-success d-none">
  <i class="bi bi-check-circle"></i> Terhubung
  </span>
  </div>
  </div>
  </div>
  <!-- setelah bagian Google Sheets, sebelum penutup container -->
  <hr>
  <h6 class="mb-3">Backup &amp; Restore</h6>

  <!-- Backup Card -->
  <div class="card border-primary mb-3">
  <div class="card-body d-flex align-items-center py-3">
  <div class="me-3 text-primary">
  <i class="bi bi-cloud-download" style="font-size: 1.8rem;"></i>
  </div>
  <div class="flex-grow-1">
  <div class="fw-semibold">Backup Data</div>
  <small class="text-muted">Cadangkan semua data keuangan Anda ke file terkompresi.</small>
  </div>
  <div>
  <button type="button" class="btn btn-primary btn-sm" data-action="backup-data" id="btn-backup">
  <i class="bi bi-download me-1"></i> Backup
  </button>
  </div>
  </div>
  </div>

  <!-- Restore Card -->
  <div class="card border-warning mb-3">
  <div class="card-body d-flex align-items-center py-3">
  <div class="me-3 text-warning">
  <i class="bi bi-cloud-upload" style="font-size: 1.8rem;"></i>
  </div>
  <div class="flex-grow-1">
  <div class="fw-semibold">Pulihkan Data</div>
  <small class="text-muted">Pulihkan data dari file backup yang telah diunduh sebelumnya.</small>
  </div>
  <div>
  <button type="button" class="btn btn-warning btn-sm" data-action="restore-modal" id="btn-restore">
  <i class="bi bi-upload me-1"></i> Pulihkan
  </button>
  </div>
  </div>
  </div>

  <!-- Input file tersembunyi untuk restore -->
  <input type="file" id="restore-file-input" accept=".json.gz,.json" style="display: none;">
  </div>`;
  document.getElementById('main-content').innerHTML = html;
  Core.populateSelectWithCurrencies(document.getElementById('setting-currency'),
    settings.default_currency);
  if (settings.default_wallet_id) document.getElementById('setting-wallet').value = settings.default_wallet_id;

  setTimeout(() => checkGoogleConnection(), 0);
}

function togglePinInput() {
  const pinEnabled = document.getElementById('pin-enabled').checked;
  document.getElementById('pin-field-group').style.display = pinEnabled ? 'block': 'none';
  if (!pinEnabled) {
    document.getElementById('pin-field').value = '';
  }
}
async function saveSettings() {
  const form = document.getElementById('settingsForm');
  const formData = new FormData(form);
  const data = Object.fromEntries(formData.entries());

  // Konversi pin_enabled ke boolean
  data.pin_enabled = data.pin_enabled === '1';

  // Hapus pin jika tidak diisi
  if (!data.pin || data.pin.length === 0) {
    delete data.pin;
  }

  try {
    tgApp.showLoading('Menyimpan...');
    await Core.api.put('/api/fintech/settings', data);
    await Core.loadUserSettings();
    tgApp.hideLoading();
    tgApp.showToast('Pengaturan disimpan');
    Core.navigateTo('home');
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menyimpan', 'danger');
  }
}

// Insights
async function renderInsightsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
  <div class="d-flex">
  <i class="bi bi-bar-chart me-2"></i>
  <h5>Analisis Keuangan</h5>
  </div>
  </div>
  <div id="insights-content">
  <div class="text-center py-5">
  <div class="spinner-border text-primary" role="status"></div>
  <p class="mt-2">Menganalisis data...</p>
  </div>
  </div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  await loadInsights();
}
async function loadInsights() {
  try {
    const res = await Core.api.get('/api/fintech/insights/full');
    renderInsightsContent(res.data);
  } catch (error) {
    document.getElementById('insights-content').innerHTML = `
    <div class="alert alert-danger">Gagal memuat analisis</div>
    `;
    tgApp.showToast(error.message || 'Gagal memuat data analisis.', 'danger');
  }
}
function renderInsightsContent(data) {
  const symbol = Core.getCurrencySymbol(data.currency || 'IDR');
  const trend = data.trend;
  const changeClass = trend.change_percentage > 0 ? 'text-danger': 'text-success';
  const changeIcon = trend.change_percentage > 0 ? '↑': '↓';

  let html = `
  <div class="container py-3">
  <!-- Summary Card -->
  <div class="card mb-3">
  <div class="card-body">
  <h6>Total Pengeluaran Bulan Ini</h6>
  <h3>${symbol} ${Core.formatNumber(trend.current_month_total)}</h3>
  <p class="${changeClass} mb-0">
  ${changeIcon} ${Math.abs(trend.change_percentage)}% dari bulan lalu
  </p>
  <small class="text-muted">Rata-rata 3 bulan: ${symbol} ${Core.formatNumber(trend.avg_last_3months)}</small>
  </div>
  </div>
  <!-- Budgets -->
  ${data.budgets && data.budgets.length > 0 ? `
  <div class="card mb-3">
  <div class="card-header">💰 Status Budget</div>
  <div class="list-group list-group-flush">
  ${data.budgets.map(b => {
    const progressClass = b.is_overspent ? 'bg-danger': (b.is_near_limit ? 'bg-warning': 'bg-success');
    return `
    <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
    <div>
    <i class="${b.category.icon} me-2" style="color:${b.category.color}"></i>
    <span class="fw-semibold">${b.category.name}</span>
    ${b.wallet ? `<small class="text-muted d-block">${b.wallet.name}</small>`: ''}
    </div>
    </div>
    <div class="mt-2">
    <div class="d-flex justify-content-between small">
    <span>${b.formatted_spending} / ${b.formatted_amount}</span>
    <span class="${b.is_overspent ? 'text-danger': (b.is_near_limit ? 'text-warning': '')}">${b.percentage}%</span>
    </div>
    <div class="progress" style="height: 6px;">
    <div class="progress-bar ${progressClass}" style="width: ${b.percentage}%"></div>
    </div>
    </div>
    ${b.is_overspent ? '<small class="text-danger">⚠️ Budget terlampaui!</small>': ''}
    ${b.is_near_limit ? '<small class="text-warning">⚡ Mendekati batas budget</small>': ''}
    </div>
    `;
  }).join('')}
  </div>
  </div>
  `: ''}

  <!-- Recommendations -->
  ${data.recommendations.length > 0 ? `
  <div class="card mb-3 border-0 shadow-sm">
  <div class="card-header bg-white border-0 pt-3">
  <h6 class="mb-0"><i class="bi bi-lightbulb text-warning me-2"></i>Rekomendasi Cerdas</h6>
  </div>
  <div class="card-body pt-0">
  ${data.recommendations.map(rec => `
    <div class="d-flex alert alert-${rec.type === 'warning' ? 'warning': (rec.type === 'success' ? 'success': 'info')} py-2 px-3 mb-2">
    <i class="bi ${rec.icon} me-3 fs-5"></i>
    <div>
    <strong>${rec.title}</strong><br>
    <small>${rec.message}</small>
    </div>
    </div>
    `).join('')}
  </div>
  </div>
  `: ''}

  <!-- Anomalies -->
  ${data.anomalies.length > 0 ? `
  <div class="card mb-3">
  <div class="card-header">⚠️ Lonjakan Pengeluaran</div>
  <div class="list-group list-group-flush">
  ${data.anomalies.map(a => `
    <div class="list-group-item">
    <div class="d-flex align-items-center">
    <i class="${a.category.icon} me-2" style="color:${a.category.color}"></i>
    <span class="flex-grow-1">${a.category.name}</span>
    <span class="text-danger">${a.formatted} (+${a.percentage_increase}%)</span>
    </div>
    </div>
    `).join('')}
  </div>
  </div>
  `: ''}

  <!-- Subscriptions -->
  ${data.subscriptions.length > 0 ? `
  <div class="card mb-3">
  <div class="card-header">📅 Langganan Bulanan</div>
  <div class="list-group list-group-flush">
  ${data.subscriptions.map(s => `
    <div class="list-group-item">
    <div>${s.category.name} · ${s.description || '-'}</div>
    <small class="text-muted">${s.formatted} / bulan · ${s.occurrences}x berturut-turut</small>
    </div>
    `).join('')}
  </div>
  </div>
  `: ''}

  <!-- Spending Ratio -->
  <div class="card mb-3">
  <div class="card-header">🥧 Komposisi Pengeluaran</div>
  <div class="card-body">
  <div class="progress mb-2" style="height: 20px;">
  <div class="progress-bar bg-success" style="width: ${data.spending_ratio.primary}%">Pokok ${data.spending_ratio.primary}%</div>
  <div class="progress-bar bg-warning" style="width: ${data.spending_ratio.secondary}%">Sekunder ${data.spending_ratio.secondary}%</div>
  <div class="progress-bar bg-danger" style="width: ${data.spending_ratio.tertiary}%">Tersier ${data.spending_ratio.tertiary}%</div>
  </div>
  </div>
  </div>

  <!-- Projection -->
  <div class="card mb-3">
  <div class="card-header">📈 Proyeksi Bulan Depan</div>
  <div class="card-body">
  <p>Estimasi Pemasukan: <strong class="text-success">${data.projection.formatted_income}</strong></p>
  <p>Estimasi Pengeluaran: <strong class="text-danger">${data.projection.formatted_expense}</strong></p>
  <p>Surplus/Defisit: <strong class="${data.projection.projected_surplus >= 0 ? 'text-success': 'text-danger'}">
  ${symbol} ${Core.formatNumber(data.projection.projected_surplus)}
  </strong></p>
  </div>
  </div>

  <!-- Top Categories -->
  <div class="card mb-3">
  <div class="card-header">🏆 Top Kategori Bulan Ini</div>
  <div class="list-group list-group-flush">
  ${data.top_categories.map((cat, i) => `
    <div class="list-group-item">
    <div class="d-flex align-items-center">
    <span class="badge bg-secondary me-2">#${i+1}</span>
    <i class="${cat.icon} me-2" style="color:${cat.color}"></i>
    <span class="flex-grow-1">${cat.name}</span>
    <strong>${cat.formatted}</strong>
    </div>
    </div>
    `).join('')}
  </div>
  </div>
  </div>
  `;
  document.getElementById('insights-content').innerHTML = html;
}

// Statements
async function renderStatementsPage() {
  await renderListPage( {
    title: 'Riwayat Statement',
    icon: 'bi-file-text',
    listContainerId: 'statement-list',
    paginationId: 'statement-pagination',
    loadFn: refreshStatementList,
    extraHeaderButtons: '<button class="btn btn-sm btn-outline-primary" onclick="showUploadStatementModal();"><i class="bi bi-cloud-upload"></i></button>'
  });
}
async function refreshStatementList(page = 1) {
  await Core.loadStatements(page);
  renderStatementList();
  Core.renderPagination('statement-pagination', Core.state.statementPage, Core.state.statementLastPage, refreshStatementList);
}
function renderStatementList() {
  const container = document.getElementById('statement-list');
  if (Core.state.statements.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada statement</p>';
    return;
  }

  let html = '<div class="list-group">';
  Core.state.statements.forEach(s => {
    const statusClass = {
      'uploaded': 'secondary',
      'decrypted': 'info',
      'parsed': 'warning',
      'imported': 'success',
      'failed': 'danger'
    }[s.status] || 'secondary';

    const icon = s.status === 'parsed' ? 'bi-hourglass-split':
    (s.status === 'imported' ? 'bi-check-circle':
      (s.status === 'failed' ? 'bi-exclamation-circle': 'bi-file-earmark'));

    html += `
    <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
    <div class="flex-grow-1 me-2" style="min-width: 0;">
    <div class="d-flex align-items-start">
    <i class="${icon} me-2 text-${statusClass} flex-shrink-0"></i>
    <span class="fw-semibold" style="overflow-wrap: anywhere;">${s.original_filename}</span>
    </div>
    <small class="text-muted d-block mt-1">Bank: ${s.bank_code || '-'} | Dompet: ${s.wallet?.name || '-'}</small>
    <div class="mt-1">
    <span class="badge bg-${statusClass}">${s.status_label}</span>
    ${s.remaining_count > 0 ? `<span class="badge bg-warning ms-1">${s.remaining_count} belum diimpor</span>`: ''}
    </div>
    <small class="text-muted d-block mt-1">${Core.formatDateTime(s.created_at)}</small>
    </div>
    <div class="dropdown flex-shrink-0">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown">
    <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
    ${s.status === 'parsed' ? `
    <li><a class="dropdown-item" href="#" onclick="renderPreviewStatementPage(${s.id})">
    <i class="bi bi-eye me-2"></i>Preview & Import
    </a></li>
    `: ''}
    <li><a class="dropdown-item text-danger" href="#" onclick="deleteStatement(${s.id})">
    <i class="bi bi-trash me-2"></i>Hapus
    </a></li>
    </ul>
    </div>
    </div>
    </div>
    `;
  });
  html += '</div>';
  container.innerHTML = html;
}
async function deleteStatement(id) {
  if (!confirm('Hapus statement ini? File dan data transaksi terkait akan dihapus permanen.')) return;
  try {
    tgApp.showLoading('Menghapus...');
    await Core.api.delete(`/api/fintech/statements/${id}`);
    tgApp.hideLoading();
    tgApp.showToast('Statement dihapus');
    await refreshStatementList(Core.state.statementPage);
  } catch (error) {
    tgApp.hideLoading();
    tgApp.showToast(error.message || 'Gagal menghapus', 'danger');
  }
}

// Budgets
async function renderBudgetsPage() {
  await renderListPage( {
    title: 'Budget & Target',
    icon: 'bi bi-pie-chart',
    listContainerId: 'budget-list',
    paginationId: 'budget-pagination',
    extraHeaderButtons: `<button class="btn btn-sm btn-primary" onclick="showAddBudgetModal()"><i class="bi bi-plus"></i></button>`,
    loadFn: refreshBudgetList
  });
}
async function refreshBudgetList() {
  await Core.loadBudgets();
  renderBudgetList();
}
async function loadBudgets() {
  try {
    const res = await Core.api.get('/api/fintech/budgets');
    Core.state.budgets = res.data || [];
  } catch (error) {
    Core.state.budgets = [];
    tgApp.showToast('Gagal memuat budget', 'danger');
  }
}
function renderBudgetList() {
  const container = document.getElementById('budget-list');
  if (!container) return;

  if (Core.state.budgets.length === 0) {
    container.innerHTML = '<p class="text-muted text-center py-4">Belum ada budget.</p>';
    return;
  }

  let html = '';
  Core.state.budgets.forEach(b => {
    const progressClass = b.is_overspent ? 'bg-danger': (b.is_near_limit ? 'bg-warning': 'bg-success');
    html += `
    <div class="card mb-3">
    <div class="card-body">
    <div class="d-flex justify-content-between align-items-start">
    <div>
    <i class="${b.category.icon} me-2" style="color:${b.category.color}"></i>
    <span class="fw-semibold">${b.category.name}</span>
    ${b.wallet ? `<small class="text-muted d-block">${b.wallet.name}</small>`: ''}
    <small class="text-muted">${b.period_label}</small>
    </div>
    <div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary border-0" data-bs-toggle="dropdown">
    <i class="bi bi-three-dots-vertical"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
    <li><a class="dropdown-item" href="#" onclick="showEditBudgetModal(${b.id})">
    <i class="bi bi-pencil me-2"></i>Edit
    </a></li>
    <li><a class="dropdown-item text-danger" href="#" onclick="deleteBudget(${b.id})">
    <i class="bi bi-trash me-2"></i>Hapus
    </a></li>
    </ul>
    </div>
    </div>
    <div class="mt-2">
    <div class="d-flex justify-content-between small">
    <span>${b.formatted_spending} / ${b.formatted_amount}</span>
    <span class="${b.is_overspent ? 'text-danger': (b.is_near_limit ? 'text-warning': '')}">${b.percentage}%</span>
    </div>
    <div class="progress" style="height: 8px;">
    <div class="progress-bar ${progressClass}" style="width: ${b.percentage}%"></div>
    </div>
    </div>
    </div>
    </div>
    `;
  });
  container.innerHTML = html;
}

// Notifications
async function renderNotificationsPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">Notifikasi</h5>
  <button class="btn btn-sm btn-outline-primary disabled" data-action="mark-all-notifications-read" id="btn-mark-all-read" disabled>
  <i class="bi bi-check-all me-1"></i>Tandai Semua Dibaca
  </button>
  </div>
  <div id="notification-list"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  await Core.loadNotifications();
}
function updateNotificationBadge() {
  const badge = document.getElementById('notification-badge');
  if (badge) {
    if (Core.state.unreadNotificationCount > 0) {
      badge.textContent = Core.state.unreadNotificationCount > 99 ? '99+': Core.state.unreadNotificationCount;
      badge.style.display = 'inline-block';
    } else {
      badge.style.display = 'none';
    }
  }
}
function renderNotificationList() {
  const container = document.getElementById('notification-list');
  if (!Core.state.notifications.length) {
    container.innerHTML = `
    <div class="text-center py-5">
    <i class="bi bi-bell-slash fs-1 text-muted"></i>
    <p class="text-muted mt-2">Belum ada notifikasi</p>
    </div>`;
    updateMarkAllButton();
    return;
  }

  container.innerHTML = Core.state.notifications.map(n => {
    const iconClass = getNotificationIcon(n.type);
    const colorClass = getNotificationColor(n.type);
    const timeAgo = Core.formatTimeAgo(n.created_at);

    return `
    <div class="card mb-2 notification-row ${n.is_read ? 'read': 'unread'}"
    style="cursor: pointer; border: none; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"
    data-action="mark-notification-read" data-id="${n.id}">
    <div class="card-body d-flex align-items-start p-3">
    <div class="notification-icon ${colorClass} me-3">
    <i class="${iconClass}"></i>
    </div>
    <div class="flex-grow-1" style="min-width: 0;">
    <div class="notification-header">
    <strong class="notification-title ${n.is_read ? 'read': ''}">${n.title}</strong>
    <span class="notification-time">${timeAgo}</span>
    </div>
    <p class="notification-message mb-0" style="word-wrap: break-word;">${n.message}</p>
    </div>
    ${n.is_read ? '': '<span class="badge bg-primary rounded-pill ms-2" style="width: 8px; height: 8px; padding: 0;"></span>'}
    </div>
    </div>
    `;
  }).join('');
  updateMarkAllButton();
}
function getNotificationIcon(type) {
  const icons = {
    'budget_warning': 'bi-exclamation-triangle',
    'cashflow_warning': 'bi-graph-down',
    'subscription_reminder': 'bi-calendar-check',
  };
  return icons[type] || 'bi-bell';
}
function getNotificationColor(type) {
  const colors = {
    'budget_warning': 'budget-warning',
    'cashflow_warning': 'cashflow-warning',
    'subscription_reminder': 'subscription-reminder',
  };
  return colors[type] || '';
}
async function markNotificationRead(id) {
  try {
    await Core.api.post(`/api/fintech/notifications/${id}/read`);
    const n = Core.state.notifications.find(x => x.id === id);
    if (n && !n.is_read) {
      n.is_read = true;
      Core.state.unreadNotificationCount = Math.max(0, Core.state.unreadNotificationCount - 1);
      updateNotificationBadge();
      renderNotificationList();
    }
  } catch (e) {
    console.error('Gagal menandai notifikasi:', e);
  }
}
async function markAllNotificationsRead() {
  try {
    await Core.api.post('/api/fintech/notifications/read-all');
    Core.state.notifications.forEach(n => n.is_read = true);
    Core.state.unreadNotificationCount = 0;
    updateNotificationBadge();
    renderNotificationList();
    tgApp.showToast('Semua notifikasi ditandai dibaca');
  } catch (e) {
    tgApp.showToast('Gagal', 'danger');
  }
}
function updateMarkAllButton() {
  const btn = document.getElementById('btn-mark-all-read');
  if (!btn) return;

  // Aktifkan tombol jika ada notifikasi yang belum dibaca
  const hasUnread = Core.state.notifications.some(n => !n.is_read);
  btn.disabled = !hasUnread;
  btn.classList.remove('disabled');
}

// Search Pages
async function renderSearchPage() {
  const html = `
  <div class="container py-3">
  <div class="input-group mb-3" id="search-input-group">
  <span class="input-group-text"><i class="bi bi-search"></i></span>
  <input type="search" id="search-input" class="form-control" placeholder="Cari transaksi, transfer...">
  <button class="btn btn-primary" data-action="perform-search">Cari</button>
  </div>
  <div id="search-filters" class="btn-group btn-group-sm w-100 mb-3 d-none" role="group">
  <button class="btn btn-outline-primary search-filter-btn active" data-filter="all" data-action="filter-search-results">
  Semua <span class="filter-badge" id="badge-all">0</span>
  </button>
  <button class="btn btn-outline-primary search-filter-btn" data-filter="transaction" data-action="filter-search-results">
  Transaksi <span class="filter-badge" id="badge-transaction">0</span>
  </button>
  <button class="btn btn-outline-primary search-filter-btn" data-filter="transfer" data-action="filter-search-results">
  Transfer <span class="filter-badge" id="badge-transfer">0</span>
  </button>
  <button class="btn btn-outline-primary search-filter-btn" data-filter="statement" data-action="filter-search-results">
  Statement <span class="filter-badge" id="badge-statement">0</span>
  </button>
  </div>
  <div id="search-results">
  <p class="text-muted text-center">Ketik minimal 2 karakter untuk mencari.</p>
  </div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  document.getElementById('search-input').focus();
}
async function performSearch() {
  const q = document.getElementById('search-input').value.trim();
  if (q.length < 2) {
    tgApp.showToast('Minimal 2 karakter', 'warning');
    return;
  }
  Core.state.searchKeyword = q;
  const container = document.getElementById('search-results');
  container.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary"></div><p>Mencari...</p></div>';

  try {
    const res = await Core.api.get(`/api/fintech/search?q=${encodeURIComponent(q)}`);
    Core.state.searchResults = res.data || [];
    updateFilterBadges(Core.state.searchResults);
    document.getElementById('search-filters').classList.remove('d-none');
    filterSearchResults('all'); // tampilkan semua & set active
  } catch (error) {
    container.innerHTML = '<p class="text-muted text-center">Gagal mencari.</p>';
  }
}
function filterSearchResults(filter) {
  Core.state.currentFilter = filter;
  document.querySelectorAll('.search-filter-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.filter === filter);
  });
  renderSearchResults(Core.state.searchResults);
}
function updateFilterBadges(results) {
  document.getElementById('badge-all').textContent = results.length;
  document.getElementById('badge-transaction').textContent = results.filter(i => i.type === 'transaction').length;
  document.getElementById('badge-transfer').textContent = results.filter(i => i.type === 'transfer').length;
  document.getElementById('badge-statement').textContent = results.filter(i => i.type === 'statement').length;
}
function renderSearchResults(results) {
  const container = document.getElementById('search-results');
  let filtered = results;
  if (Core.state.currentFilter !== 'all') {
    filtered = results.filter(item => item.type === Core.state.currentFilter);
  }

  if (!filtered.length) {
    container.innerHTML = '<p class="text-muted text-center py-5">Tidak ditemukan.</p>';
    return;
  }

  container.innerHTML = filtered.map(item => {
    const desc = Core.highlightText(item.description || '', Core.state.searchKeyword);
    if (item.type === 'transaction') {
      return `
      <div class="card search-result-item" data-action="show-search-detail" data-type="${item.type}" data-id="${item.id}">
      <div class="card-body d-flex align-items-center p-3">
      <i class="${item.icon} me-3 fs-4" style="color:${item.color}"></i>
      <div class="flex-grow-1" style="min-width:0;">
      <div class="fw-semibold text-truncate">${desc}</div>
      <small class="text-muted">${item.wallet} · ${Core.formatDate(item.date)}</small>
      </div>
      <span class="${item.transaction_type === 'income' ? 'text-success': 'text-danger'} fw-bold ms-2">${item.amount}</span>
      </div>
      </div>
      `;
    } else if (item.type === 'transfer') {
      return `
      <div class="card search-result-item" data-action="show-search-detail" data-type="${item.type}" data-id="${item.id}">
      <div class="card-body d-flex align-items-center p-3">
      <i class="${item.icon} me-3 fs-4" style="color:${item.color}"></i>
      <div class="flex-grow-1" style="min-width:0;">
      <div class="fw-semibold text-truncate">${desc}</div>
      <small class="text-muted">Transfer · ${Core.formatDate(item.date)}</small>
      </div>
      <span class="fw-bold ms-2">${item.amount}</span>
      </div>
      </div>
      `;
    } else if (item.type === 'statement') {
      return `
      <div class="card search-result-item" data-action="show-search-detail" data-type="${item.type}" data-id="${item.id}">
      <div class="card-body d-flex align-items-center p-3">
      <i class="${item.icon} me-3 fs-4" style="color:${item.color}"></i>
      <div class="flex-grow-1" style="min-width:0;">
      <div class="fw-semibold text-truncate">${desc}</div>
      <small class="text-muted">${item.bank_code} · ${item.wallet} · ${item.status}</small>
      </div>
      <small class="text-muted ms-2">${Core.formatDate(item.date)}</small>
      </div>
      </div>
      `;
    }
  }).join('');
}
function showSearchDetail(type, id) {
  const item = Core.state.searchResults.find(i => i.type === type && i.id == id);
  if (!item) return;

  const title = document.getElementById('searchDetailModalTitle');
  const body = document.getElementById('searchDetailBody');
  const actionBtn = document.getElementById('searchDetailActionBtn');

  if (type === 'transaction') {
    title.textContent = item.category || 'Transaksi';
    body.innerHTML = `
    <div class="text-center mb-3">
    <i class="${item.icon} fs-1" style="color:${item.color}"></i>
    <h5 class="mt-2">${item.category}</h5>
    <span class="badge bg-secondary">${item.transaction_type === 'income' ? 'Pemasukan': 'Pengeluaran'}</span>
    </div>
    <table class="table table-sm">
    <tr><th>Jumlah</th><td class="${item.transaction_type === 'income' ? 'text-success': 'text-danger'} fw-bold">${item.amount}</td></tr>
    <tr><th>Dompet</th><td>${item.wallet}</td></tr>
    <tr><th>Tanggal</th><td>${Core.formatDate(item.date)}</td></tr>
    <tr><th>Deskripsi</th><td>${Core.highlightText(item.description || '-', Core.state.searchKeyword)}</td></tr>
    </table>
    `;
    actionBtn.style.display = 'block';
    actionBtn.textContent = 'Edit Transaksi';
    actionBtn.onclick = () => {
      Core.state.pendingAction = {
        type: 'transaction',
        id: item.id
      };
      bootstrap.Modal.getInstance(document.getElementById('searchDetailModal')).hide();
      Core.navigateTo('transactions');
    };
  } else if (type === 'transfer') {
    title.textContent = 'Transfer';
    body.innerHTML = `
    <div class="text-center mb-3">
    <i class="bi bi-arrow-left-right fs-1 text-info"></i>
    <h5 class="mt-2">Transfer</h5>
    </div>
    <table class="table table-sm">
    <tr><th>Dari</th><td>${item.from_wallet}</td></tr>
    <tr><th>Ke</th><td>${item.to_wallet}</td></tr>
    <tr><th>Jumlah</th><td class="fw-bold">${item.amount}</td></tr>
    <tr><th>Tanggal</th><td>${Core.formatDate(item.date)}</td></tr>
    <tr><th>Deskripsi</th><td>${Core.highlightText(item.description || '-', Core.state.searchKeyword)}</td></tr>
    </table>
    `;
    actionBtn.style.display = 'block';
    actionBtn.textContent = 'Edit Transfer';
    actionBtn.onclick = () => {
      Core.state.pendingAction = {
        type: 'transfer',
        id: item.id
      };
      bootstrap.Modal.getInstance(document.getElementById('searchDetailModal')).hide();
      Core.navigateTo('transfers');
    };
  } else if (type === 'statement') {
    title.textContent = item.description;
    body.innerHTML = `
    <div class="text-center mb-3">
    <i class="bi bi-file-text fs-1 text-secondary"></i>
    <h5 class="mt-2">${item.description}</h5>
    <span class="badge bg-secondary">${item.bank_code}</span>
    </div>
    <table class="table table-sm">
    <tr><th>Bank</th><td>${item.bank_code}</td></tr>
    <tr><th>Dompet</th><td>${item.wallet}</td></tr>
    <tr><th>Status</th><td>${item.status}</td></tr>
    <tr><th>Tanggal Upload</th><td>${Core.formatDate(item.date)}</td></tr>
    </table>
    `;
    actionBtn.style.display = 'block';
    actionBtn.textContent = 'Lihat Statement';
    actionBtn.onclick = () => {
      bootstrap.Modal.getInstance(document.getElementById('searchDetailModal')).hide();
      Core.navigateTo('statements');
    };
  }

  new bootstrap.Modal(document.getElementById('searchDetailModal')).show();
}

// Trash
window.renderTransactionTrash = function() {
  Core.state.currentPage = 'trash';
  renderTrashPage();
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
}
async function renderTrashPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex align-items-center mb-3">
  <button class="btn btn-link me-2" onclick="Core.navigateTo('transactions')"><i class="bi bi-arrow-left"></i></button>
  <h5 class="mb-0">Tempat Sampah Transaksi</h5>
  </div>
  <div id="trash-list"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  const res = await Core.api.get('/api/fintech/transactions/trashed');
  const trashed = res.data.data || [];
  const container = document.getElementById('trash-list');
  if (!trashed.length) {
    container.innerHTML = '<p class="text-muted text-center">Kosong</p>';
    return;
  }
  container.innerHTML = trashed.map(t => `
    <div class="card mb-2">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div>
    <div>${t.category.name} · ${t.formatted_amount}</div>
    <small class="text-muted">${t.wallet.name} · ${Core.formatDate(t.transaction_date)}</small>
    </div>
    <div>
    <button class="btn btn-sm btn-outline-success" data-action="restore-transaction" data-id="${t.id}"><i class="bi bi-arrow-counterclockwise"></i></button>
    <button class="btn btn-sm btn-outline-danger" data-action="force-delete-transaction" data-id="${t.id}"><i class="bi bi-trash"></i></button>
    </div>
    </div>
    </div>
    </div>
    `).join('');
}
async function restoreTransaction(id) {
  await Core.api.post(`/api/fintech/transactions/${id}/restore`);
  await Core.loadWallets();
  await Core.loadHomeSummary();
  Core.navigateTo('transactionTrash');
}
async function forceDeleteTransaction(id) {
  if (!confirm('Hapus permanen?')) return;
  await Core.api.delete(`/api/fintech/transactions/${id}/force`);
  Core.navigateTo('transactionTrash');
}
window.renderTransferTrash = function() {
  Core.state.currentPage = 'transfer-trash';
  renderTransferTrashPage();
  document.querySelectorAll('.nav-btn').forEach(btn => btn.classList.remove('active'));
}
async function renderTransferTrashPage() {
  const html = `
  <div class="container py-3">
  <div class="d-flex align-items-center mb-3">
  <button class="btn btn-link me-2" onclick="Core.navigateTo('transfers')"><i class="bi bi-arrow-left"></i></button>
  <h5 class="mb-0">Tempat Sampah Transfer</h5>
  </div>
  <div id="transfer-trash-list"></div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  const res = await Core.api.get('/api/fintech/transfers/trashed');
  const trashed = res.data.data || [];
  const container = document.getElementById('transfer-trash-list');
  if (!trashed.length) {
    container.innerHTML = '<p class="text-muted text-center">Kosong</p>';
    return;
  }
  container.innerHTML = trashed.map(t => `
    <div class="card mb-2">
    <div class="card-body">
    <div class="d-flex justify-content-between">
    <div>
    <div>${t.from_wallet.name} → ${t.to_wallet.name}</div>
    <div class="text-primary">${t.formatted_amount}</div>
    <small class="text-muted">${Core.formatDate(t.transfer_date)}</small>
    </div>
    <div>
    <button class="btn btn-sm btn-outline-success" data-action="restore-transfer" data-id="${t.id}"><i class="bi bi-arrow-counterclockwise"></i></button>
    <button class="btn btn-sm btn-outline-danger" data-action="force-delete-transfer" data-id="${t.id}"><i class="bi bi-trash"></i></button>
    </div>
    </div>
    </div>
    </div>
    `).join('');
}
async function restoreTransfer(id) {
  await Core.api.post(`/api/fintech/transfers/${id}/restore`);
  await loadWallets();
  await loadHomeSummary();
  renderTransferTrashPage();
}
async function forceDeleteTransfer(id) {
  if (!confirm('Hapus permanen?')) return;
  await Core.api.delete(`/api/fintech/transfers/${id}/force`);
  renderTransferTrashPage();
}

// ==================== EXPORT PAGE ====================
async function renderExportPage() {
  const html = `
  <div class="export-page">
  <div class="container py-3">
  <div class="d-flex justify-content-between align-items-center mb-4">
  <div>
  <h3 class="fw-bold mb-0"><i class="bi bi-cloud-arrow-down-fill me-2"></i>Ekspor Data Keuangan</h3>
  </div>
  <button class="btn btn-outline-secondary btn-sm rounded-circle" style="width: 36px; height: 36px;"
  data-action="show-export-guide" title="Panduan Ekspor">
  <i class="bi bi-question-lg"></i>
  </button>
  </div>

  <div class="card border-0 shadow-sm" style="background-color: var(--tg-theme-secondary-bg-color); color: var(--tg-theme-text-color);">
  <div class="card-body p-4">
  <div class="mb-4">
  <label class="form-label fw-semibold">
  <i class="bi bi-stack me-2"></i>Jenis Data
  </label>
  <select class="form-select" id="export-type" data-action="change-export-type"
  style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color); border-color: var(--tg-theme-hint-color);">
  <option value="transactions" selected>Transaksi</option>
  <option value="transfers">Transfer</option>
  <option value="budgets">Budget</option>
  <option value="all">Semua Data</option>
  </select>
  </div>

  <div id="export-filter-container" class="mb-4"></div>

  <div class="mb-4">
  <label class="form-label fw-semibold">
  <i class="bi bi-file-earmark me-2"></i>Format File
  </label>
  <div class="d-flex gap-3 flex-wrap">
  <div class="form-check">
  <input class="form-check-input" type="radio" name="export-format" id="format-xlsx" value="xlsx" checked data-action="change-export-format">
  <label class="form-check-label" for="format-xlsx">
  <i class="bi bi-file-earmark-spreadsheet text-success me-1"></i> Excel
  </label>
  </div>
  <div class="form-check">
  <input class="form-check-input" type="radio" name="export-format" id="format-pdf" value="pdf" data-action="change-export-format">
  <label class="form-check-label" for="format-pdf">
  <i class="bi bi-file-earmark-pdf text-danger me-1"></i> PDF
  </label>
  </div>
  <div class="form-check">
  <input class="form-check-input" type="radio" name="export-format" id="format-csv" value="csv" data-action="change-export-format">
  <label class="form-check-label" for="format-csv">
  <i class="bi bi-file-earmark-text text-secondary me-1"></i> CSV
  </label>
  </div>
  <div class="form-check">
  <input class="form-check-input" type="radio" name="export-format" id="format-gsheet" value="gsheet" data-action="change-export-format">
  <label class="form-check-label" for="format-gsheet">
  <i class="bi bi-google text-primary me-1"></i> Google Sheets
  </label>
  </div>
  </div>
  </div>

  <button class="btn btn-primary btn-lg w-100 d-flex align-items-center justify-content-center gap-2"
  data-action="export-data">
  <i class="bi bi-rocket-takeoff"></i> Ekspor Sekarang
  </button>
  </div>
  </div>
  </div>

  <!-- Modal Panduan (diperbarui) -->
  <div class="modal fade" id="exportGuideModal" tabindex="-1" aria-labelledby="exportGuideModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
  <div class="modal-content" style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color);">
  <div class="modal-header">
  <h5 class="modal-title fw-bold" id="exportGuideModalLabel"><i class="bi bi-info-circle-fill text-info me-2"></i>Panduan Lengkap Ekspor</h5>
  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" style="filter: invert(1);"></button>
  </div>
  <div class="modal-body">
  <div class="mb-4">
  <h6 class="fw-bold"><i class="bi bi-1-circle-fill text-primary me-2"></i>Mulai Bot Telegram</h6>
  <p class="small opacity-75">Sebelum mengekspor, pastikan Anda sudah memulai bot kami. Klik tombol di bawah untuk membuka chat:</p>
  <button class="btn btn-sm" style="background-color: #0088cc; color: white;"
  data-action="open-bot-chat" data-bot-link="https://t.me/${BOT_USERNAME}?start=export">
  <i class="bi bi-telegram me-1"></i> Buka @${BOT_USERNAME}
  </button>
  </div>
  <div class="mb-4">
  <h6 class="fw-bold"><i class="bi bi-2-circle-fill text-primary me-2"></i>Pilih Dompet</h6>
  <p class="small opacity-75">Pilih <strong>dompet</strong> yang datanya ingin diekspor. Field ini wajib diisi dan menentukan mata uang yang digunakan di dalam laporan.</p>
  </div>
  <div class="mb-4">
  <h6 class="fw-bold"><i class="bi bi-3-circle-fill text-primary me-2"></i>Pilih Jenis Data & Filter</h6>
  <p class="small opacity-75">Pilih tipe data yang akan diekspor. Filter yang muncul akan menyesuaikan:</p>
  <ul class="small opacity-75">
  <li><strong>Transaksi:</strong> Bisa difilter berdasarkan rentang tanggal, bulan, tipe (pemasukan/pengeluaran), dan kategori. Kategori ditampilkan sebagai badge interaktif yang bisa diklik untuk memilih.</li>
  <li><strong>Transfer:</strong> Bisa difilter berdasarkan rentang tanggal.</li>
  <li><strong>Budget:</strong> Bisa difilter berdasarkan tipe periode (bulanan/tahunan), bulan/tahun, status (terlampaui/mendekati/aman), dan kategori (khusus pengeluaran).</li>
  <li><strong>Semua Data:</strong> Hanya rentang tanggal yang tersedia sebagai filter.</li>
  </ul>
  </div>
  <div class="mb-4">
  <h6 class="fw-bold"><i class="bi bi-4-circle-fill text-primary me-2"></i>Pilih Format File</h6>
  <p class="small opacity-75">
  <strong>Excel (.xlsx):</strong> Data lengkap dengan tabel, subtotal, metadata, serta opsi tambahan seperti chart, ringkasan bulanan, dan top 5 pengeluaran tertinggi.<br>
  <strong>PDF:</strong> Laporan siap cetak dengan tampilan profesional. Maksimal 500 baris data.<br>
  <strong>CSV:</strong> Format ringan untuk diimpor ke spreadsheet lain (Excel, Google Sheets, dsb).<br>
  <strong>Google Sheets:</strong> Data langsung dikirim ke spreadsheet Google Sheets Anda. <em>Wajib hubungkan akun Google terlebih dahulu di halaman Pengaturan.</em>
  </p>
  </div>
  <div class="mb-4">
  <h6 class="fw-bold"><i class="bi bi-5-circle-fill text-primary me-2"></i>Opsi Lanjutan</h6>
  <p class="small opacity-75">Klik bagian <strong>"Lanjutan"</strong> untuk mengatur opsi tambahan:</p>
  <ul class="small opacity-75">
  <li><strong>Sertakan Deskripsi:</strong> Menampilkan kolom deskripsi di laporan.</li>
  <li><strong>Sertakan Chart:</strong> Menyertakan grafik batang pemasukan vs pengeluaran (hanya untuk format Excel dan PDF).</li>
  <li><strong>Sertakan Ringkasan Bulanan:</strong> Menambahkan tabel ringkasan bulanan di samping data utama (Excel dan PDF).</li>
  <li><strong>Sertakan Top 5 Pengeluaran Tertinggi:</strong> Menampilkan 5 transaksi pengeluaran terbesar (Excel dan PDF).</li>
  </ul>
  </div>
  <div>
  <h6 class="fw-bold"><i class="bi bi-6-circle-fill text-primary me-2"></i>Ekspor & Cek Telegram</h6>
  <p class="small opacity-75">Setelah semua filter dan opsi diatur, klik <strong>Ekspor Sekarang</strong>. File akan diproses oleh sistem dan dikirimkan langsung ke chat Telegram Anda dalam beberapa saat. Pastikan koneksi internet stabil.</p>
  </div>
  </div>
  <div class="modal-footer">
  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
  </div>
  </div>
  </div>
  </div>
  `;
  document.getElementById('main-content').innerHTML = html;
  renderExportFilters('transactions');
  updateExportFormatAvailability();
}

function renderExportFilters(type) {
  const container = document.getElementById('export-filter-container');
  if (!container) return;

  const currentYear = new Date().getFullYear();
  let yearOptions = '<option value="">Semua Tahun</option>';
  for (let year = currentYear; y >= currentYear - 10, y--) {
    yearOptions += `<option value="${y}">${y}</option>`;
  }

  let html = '';

  // Wallet (required)
  const defaultWalletId = Core.state.userSettings?.default_wallet_id || (Core.state.wallets[0]?.id ?? '');
  html += `
  <div class="mb-3">
  <label for="filter-wallet" class="form-label fw-semibold">
  <i class="bi bi-wallet2 me-2"></i>Dompet <span class="text-danger">*</span>
  </label>
  <select class="form-select" id="filter-wallet"
  style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color); border-color: var(--tg-theme-hint-color);">
  ${Core.state.wallets.map(w => `<option value="${w.id}" ${w.id == defaultWalletId ? 'selected': ''}>${w.name} (${w.currency?.symbol || ''})</option>`).join('')}
  </select>
  </div>`;

  // ALL
  if (type === 'all') {
    html += `
    <div class="row mb-3">
    <div class="col">
    <label for="filter-date-from" class="form-label">Dari Tanggal</label>
    <input type="date" class="form-control" id="filter-date-from" data-action="change-start-date">
    </div>
    <div class="col">
    <label for="filter-date-to" class="form-label">Sampai Tanggal</label>
    <input type="date" class="form-control" id="filter-date-to">
    </div>
    <div class="col">
    <label for="filter-year" class="form-label">Tahun</label>
    <select class="form-select" id="filter-year"
    style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color); border-color: var(--tg-theme-hint-color);">
    ${yearOptions}
    </select>
    </div>
    </div>`;
    container.innerHTML = html;
    updateExportFormatAvailability();
    return;
  }

  // TRANSACTIONS
  if (type === 'transactions') {
    html += `
    <div class="row mb-3">
    <div class="col">
    <label for="filter-date-from" class="form-label">Dari Tanggal</label>
    <input type="date" class="form-control" id="filter-date-from" data-action="change-start-date">
    </div>
    <div class="col">
    <label for="filter-date-to" class="form-label">Sampai Tanggal</label>
    <input type="date" class="form-control" id="filter-date-to">
    </div>
    </div>
    <div class="mb-3">
    <label for="filter-month" class="form-label">Atau Bulan (abaikan rentang tanggal)</label>
    <input type="month" class="form-control" id="filter-month">
    </div>
    <div class="mb-3">
    <label for="filter-year" class="form-label">Tahun</label>
    <select class="form-select" id="filter-year"
    style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color); border-color: var(--tg-theme-hint-color);">
    ${yearOptions}
    </select>
    </div>
    <div class="mb-3">
    <label for="filter-type" class="form-label">Tipe Transaksi</label>
    <select class="form-select" id="filter-type" data-action="change-transaction-type">
    <option value="">Semua</option>
    <option value="income">Pemasukan</option>
    <option value="expense">Pengeluaran</option>
    </select>
    </div>
    <div class="mb-3">
    <div id="category-badges" class="d-flex flex-wrap gap-2 mb-2"></div>
    <select class="d-none" id="filter-category-hidden" multiple></select>
    </div>

    <!-- Advanced Options Accordion -->
    <div class="accordion mb-3" id="advancedAccordion">
    <div class="accordion-item">
    <h2 class="accordion-header" id="headingAdvanced">
    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdvanced" aria-expanded="false" aria-controls="collapseAdvanced">
    <i class="bi bi-sliders me-2"></i> Opsi Lanjutan
    </button>
    </h2>
    <div id="collapseAdvanced" class="accordion-collapse collapse" aria-labelledby="headingAdvanced" data-bs-parent="#advancedAccordion">
    <div class="accordion-body">
    <div class="mb-3">
    <div class="form-check">
    <input class="form-check-input" type="checkbox" id="include-description" checked>
    <label for="include-description" class="form-check-label">
    <strong>Sertakan Deskripsi</strong><br>
    <small class="text-muted">Menampilkan kolom deskripsi pada tiap transaksi.</small>
    </label>
    </div>
    </div>
    <div id="export-options" style="display: none;">
    <div class="mb-3">
    <div class="form-check">
    <input class="form-check-input" type="checkbox" id="include-chart">
    <label for="include-chart" class="form-check-label">
    <strong>Sertakan Chart</strong><br>
    <small class="text-muted">Menampilkan grafik batang pemasukan vs pengeluaran.</small>
    </label>
    </div>
    </div>
    <div class="mb-3">
    <div class="form-check">
    <input class="form-check-input" type="checkbox" id="include-monthly-summary">
    <label for="include-monthly-summary" class="form-check-label">
    <strong>Sertakan Ringkasan Bulanan</strong><br>
    <small class="text-muted">Menampilkan tabel ringkasan pemasukan, pengeluaran, dan net per bulan.</small>
    </label>
    </div>
    </div>
    <div class="mb-3">
    <div class="form-check">
    <input class="form-check-input" type="checkbox" id="include-top5">
    <label for="include-top5" class="form-check-label">
    <strong>Sertakan Top 5 Transaksi</strong><br>
    <small class="text-muted">Menampilkan 5 pemasukan dan pengeluaran tertinggi.</small>
    </label>
    </div>
    </div>
    <div class="mb-3">
    <div class="form-check">
    <input class="form-check-input" type="checkbox" id="include-category-expense">
    <label for="include-category-expense" class="form-check-label">
    <strong>Sertakan Persentase Kategori Pengeluaran</strong><br>
    <small class="text-muted">Menampilkan tabel distribusi pengeluaran per kategori beserta pie chart.</small>
    </label>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>
    </div>`;

    container.innerHTML = html;
    updateTransactionCategoryFilter();
    toggleExportOptions();
    updateExportFormatAvailability();
    return;
  }

  // TRANSFERS
  if (type === 'transfers') {
    html += `
    <div class="row mb-3">
    <div class="col">
    <label for="filter-date-from" class="form-label">Dari Tanggal</label>
    <input type="date" class="form-control" id="filter-date-from" data-action="change-start-date">
    </div>
    <div class="col">
    <label for="filter-date-to" class="form-label">Sampai Tanggal</label>
    <input type="date" class="form-control" id="filter-date-to">
    </div>
    <div class="col">
    <label for="filter-year" class="form-label">Tahun</label>
    <select class="form-select" id="filter-year"
    style="background-color: var(--tg-theme-bg-color); color: var(--tg-theme-text-color); border-color: var(--tg-theme-hint-color);">
    ${yearOptions}
    </select>
    </div>
    </div>
    <div class="accordion mb-3" id="advancedAccordion">
    <div class="accordion-item">
    <h2 class="accordion-header" id="headingAdvanced">
    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdvanced" aria-expanded="false" aria-controls="collapseAdvanced">
    <i class="bi bi-sliders me-2"></i> Lanjutan
    </button>
    </h2>
    <div id="collapseAdvanced" class="accordion-collapse collapse" aria-labelledby="headingAdvanced" data-bs-parent="#advancedAccordion">
    <div class="accordion-body">
    <div class="form-check mb-0">
    <input class="form-check-input" type="checkbox" id="include-description" checked>
    <label for="include-description" class="form-check-label">Sertakan Deskripsi</label>
    </div>
    </div>
    </div>
    </div>
    </div>`;
    container.innerHTML = html;
    updateExportFormatAvailability();
    return;
  }

  // BUDGETS
  if (type === 'budgets') {
    html += `
    <div class="mb-3">
    <label for="filter-period-type" class="form-label">Tipe Periode</label>
    <select class="form-select" id="filter-period-type" data-action="change-budget-period">
    <option value="monthly" selected>Bulanan</option>
    <option value="yearly">Tahunan</option>
    </select>
    </div>
    <div class="mb-3" id="budget-period-detail"></div>
    <div class="mb-3">
    <label for="filter-status" class="form-label">Status Budget</label>
    <select class="form-select" id="filter-status">
    <option value="">Semua</option>
    <option value="overspent">Terlampaui (≥100%)</option>
    <option value="near_limit">Mendekati (80-99%)</option>
    <option value="on_track">Aman (<80%)</option>
    </select>
    </div>
    <div class="mb-3">
    <label class="form-label">Kategori</label>
    <div id="category-badges" class="d-flex flex-wrap gap-2 mb-2"></div>
    <select class="d-none" id="filter-category-hidden" multiple></select>
    </div>`;
    container.innerHTML = html;
    renderBudgetPeriodInput();
    Core.state.currentFilteredCategories = Core.state.categories.filter(c => c.type === 'expense' || c.type === 'both');
    renderCategoryBadges(Core.state.currentFilteredCategories);
    updateExportFormatAvailability();
    return;
  }
}

function toggleExportOptions() {
  const optionsDiv = document.getElementById('export-options');
  if (!optionsDiv) return;

  const formatXlsx = document.getElementById('format-xlsx');
  const formatPdf = document.getElementById('format-pdf');
  const formatGsheet = document.getElementById('format-gsheet');
  const isExcel = formatXlsx && formatXlsx.checked;
  const isPdf = formatPdf && formatPdf.checked;
  const isGsheet = formatGsheet && formatGsheet.checked;

  if (isExcel || isPdf || isGsheet) {
    optionsDiv.style.display = 'block';
  } else {
    optionsDiv.style.display = 'none';
  }
}

function renderBudgetPeriodInput() {
  const periodType = document.getElementById('filter-period-type')?.value;
  const container = document.getElementById('budget-period-detail');
  if (!container) return;

  if (periodType === 'monthly') {
    const currentMonth = new Date().toISOString().slice(0, 7);
    container.innerHTML = `
    <label for="filter-month" class="form-label">Bulan</label>
    <input type="month" class="form-control" id="filter-month" value="${currentMonth}">`;
  } else {
    const currentYear = new Date().getFullYear();
    container.innerHTML = `
    <label for="filter-year" class="form-label">Tahun</label>
    <input type="number" class="form-control" id="filter-year" value="${currentYear}" min="2000" max="${currentYear}">`;
  }
}

function updateTransactionCategoryFilter() {
  const typeSelect = document.getElementById('filter-type');
  const badgesContainer = document.getElementById('category-badges');
  if (!typeSelect || !badgesContainer) return;

  const selectedType = typeSelect.value; // '' (semua), 'income', 'expense'

  if (selectedType === '' || selectedType === 'all') {
    badgesContainer.innerHTML = '';
    const hiddenSelect = document.getElementById('filter-category-hidden');
    if (hiddenSelect) hiddenSelect.innerHTML = '';
    Core.state.currentFilteredCategories = [];
    return;
  }

  let filteredCategories = Core.state.categories;
  if (selectedType === 'income') {
    filteredCategories = Core.state.categories.filter(c => ['income', 'both'].includes(c.type));
  } else if (selectedType === 'expense') {
    filteredCategories = Core.state.categories.filter(c => ['expense', 'both'].includes(c.type));
  }

  Core.state.currentFilteredCategories = filteredCategories;
  renderCategoryBadges(filteredCategories);
}

function renderCategoryBadges(categories = null) {
  const catList = categories || Core.state.currentFilteredCategories;
  const badgesContainer = document.getElementById('category-badges');
  const hiddenSelect = document.getElementById('filter-category-hidden');
  if (!badgesContainer || !hiddenSelect) return;

  const selectedValues = [...hiddenSelect.options]
  .filter(opt => opt.selected)
  .map(opt => opt.value);

  hiddenSelect.innerHTML = '';
  let html = '<label class="form-label mb-2">Kategori</label>';

  catList.forEach(cat => {
    const isSelected = selectedValues.includes(cat.id.toString());
    const bgColor = cat.color || '#6c757d';
    const opacityStyle = isSelected ? '1': '0.45';
    const borderStyle = isSelected ? 'border border-2 border-dark': '';
    const checkIcon = isSelected ? '<i class="bi bi-check-circle-fill ms-1 small"></i>': '';

    html += `
    <span class="badge rounded-pill d-inline-flex align-items-center"
    style="background-color: ${bgColor}; opacity: ${opacityStyle}; cursor: pointer; ${borderStyle}; font-size: 0.85rem; padding: 0.5rem 0.85rem;"
    data-action="toggle-category-badge"
    data-category-id="${cat.id}">
    <i class="${cat.icon || 'bi-tag'} me-1"></i>${cat.name}${checkIcon}
    </span>`;

    const option = document.createElement('option');
    option.value = cat.id;
    option.selected = isSelected;
    hiddenSelect.appendChild(option);
  });

  badgesContainer.innerHTML = html;
}

function toggleCategoryBadge(categoryId) {
  const hiddenSelect = document.getElementById('filter-category-hidden');
  if (!hiddenSelect) return;

  const option = [...hiddenSelect.options].find(opt => opt.value === categoryId);
  if (option) {
    option.selected = !option.selected;
    renderCategoryBadges(Core.state.currentFilteredCategories);
  }
}

async function updateExportFormatAvailability() {
  const type = document.getElementById('export-type').value;
  const formatPdf = document.getElementById('format-pdf');
  const formatXlsx = document.getElementById('format-xlsx');
  const formatCsv = document.getElementById('format-csv');
  const formatGsheet = document.getElementById('format-gsheet');
  const labelGsheet = document.querySelector('label[for="format-gsheet"]');
  const labels = document.querySelectorAll('label[for="format-pdf"], label[for="format-csv"]');

  if (!formatPdf || !formatCsv || !formatGsheet) return;

  if (type === 'all') {
    // Hanya Excel & Google Sheets untuk all
    formatPdf.disabled = true;
    formatPdf.checked = false;
    formatCsv.disabled = true;
    formatCsv.checked = false;
    formatXlsx.checked = true;
    labels.forEach(l => l?.classList.add('text-muted'));
  } else {
    formatPdf.disabled = false;
    formatCsv.disabled = false;
    labels.forEach(l => l?.classList.remove('text-muted'));
  }

  const connected = await checkGoogleConnection();
  if (formatGsheet) {
    if (connected) {
      formatGsheet.disabled = false;
      labelGsheet?.classList.remove('text-muted');
    } else {
      formatGsheet.disabled = true;
      formatGsheet.checked = false;
      labelGsheet?.classList.add('text-muted');
      if (document.querySelector('input[name="export-format"]:checked') === formatGsheet) {
        formatXlsx.checked = true;
      }
    }
  }
}

async function performExport() {
  const type = document.getElementById('export-type').value;
  const formatRadio = document.querySelector('input[name="export-format"]:checked');
  const format = formatRadio ? formatRadio.value: 'xlsx';
  const walletEl = document.getElementById('filter-wallet');
  if (!walletEl) {
    tgApp.showToast('Komponen dompet tidak ditemukan.', 'danger');
    return;
  }
  const walletId = walletEl.value;
  if (!walletId) {
    tgApp.showToast('Pilih dompet terlebih dahulu', 'warning');
    return;
  }

  const payload = {
    type,
    format,
    wallet_id: walletId
  };

  try {
    if (type === 'all') {
      payload.date_from = document.getElementById('filter-date-from')?.value || undefined;
      payload.date_to = document.getElementById('filter-date-to')?.value || undefined;
      payload.year = document.getElementById('filter-year')?.value || undefined;
    } else if (type === 'transactions') {
      payload.date_from = document.getElementById('filter-date-from')?.value || undefined;
      payload.date_to = document.getElementById('filter-date-to')?.value || undefined;
      payload.month = document.getElementById('filter-month')?.value || undefined;
      payload.year = document.getElementById('filter-year')?.value || undefined;
      payload.transaction_type = document.getElementById('filter-type')?.value || undefined;
      payload.include_description = document.getElementById('include-description')?.checked ?? true;
      const hiddenSelect = document.getElementById('filter-category-hidden');
      if (hiddenSelect) {
        const selected = [...hiddenSelect.selectedOptions].map(o => o.value);
        if (selected.length) payload.category_ids = selected;
      }
      // Opsi tambahan hanya jika format Excel
      if (format === 'xlsx' || format === 'pdf' || format === 'gsheet') {
        payload.include_chart = document.getElementById('include-chart')?.checked ?? false;
        payload.include_monthly_summary = document.getElementById('include-monthly-summary')?.checked ?? false;
        payload.include_top5 = document.getElementById('include-top5')?.checked ?? false;
        payload.include_category_expense = document.getElementById('include-category-expense')?.checked ?? false;
      } else {
        payload.include_chart = false;
        payload.include_monthly_summary = false;
        payload.include_top5 = false;
      }
    } else if (type === 'transfers') {
      payload.date_from = document.getElementById('filter-date-from')?.value || undefined;
      payload.date_to = document.getElementById('filter-date-to')?.value || undefined;
      payload.year = document.getElementById('filter-year')?.value || undefined;
      payload.include_description = document.getElementById('include-description')?.checked ?? true;
    } else if (type === 'budgets') {
      const periodTypeEl = document.getElementById('filter-period-type');
      if (!periodTypeEl) {
        tgApp.showToast('Form tipe periode tidak tersedia.', 'danger');
        return;
      }
      const periodType = periodTypeEl.value;
      payload.period_type = periodType;
      if (periodType === 'monthly') {
        const monthEl = document.getElementById('filter-month');
        if (monthEl) payload.month = monthEl.value;
      } else {
        const yearEl = document.getElementById('filter-year');
        if (yearEl) payload.year = yearEl.value;
      }
      payload.status = document.getElementById('filter-status')?.value || undefined;
      const hiddenSelect = document.getElementById('filter-category-hidden');
      if (hiddenSelect) {
        const selected = [...hiddenSelect.selectedOptions].map(o => o.value);
        if (selected.length) payload.category_ids = selected;
      }
      payload.include_description = document.getElementById('include-description')?.checked ?? true;
    }

    tgApp.showLoading('Mengekspor...');
    const res = await Core.api.post('/api/fintech/exports', payload);
    tgApp.hideLoading();
    tgApp.showToast(res.message || 'File berhasil dikirim ke Telegram Anda.');
  } catch (err) {
    tgApp.hideLoading();
    tgApp.showToast(err.message || 'Gagal mengekspor.', 'danger');
  }
}

async function checkGoogleConnection() {
  try {
    const res = await Core.api.get('/api/fintech/oauth/google/status');
    const btn = document.getElementById('btn-connect-google');
    const badge = document.getElementById('google-connected-badge');
    if (btn && badge) {
      if (res.connected) {
        btn.classList.add('d-none');
        badge.classList.remove('d-none');
      } else {
        btn.classList.remove('d-none');
        badge.classList.add('d-none');
      }
    }
    return res.connected;
  } catch (e) {
    console.error('Gagal cek status Google:', e);
    return false;
  }
}

// ==================== DAFTAR HALAMAN ====================
Core.setPages({
  home: renderHomePage,
  transactions: renderTransactionsPage,
  transfers: renderTransfersPage,
  wallets: renderWalletsPage,
  reports: renderReportsPage,
  settings: renderSettingsPage,
  insights: renderInsightsPage,
  statements: renderStatementsPage,
  budgets: renderBudgetsPage,
  notifications: renderNotificationsPage,
  search: renderSearchPage,
  transactionTrash: renderTransactionTrash,
  transferTrash: renderTransferTrash,
  export: renderExportPage,
});