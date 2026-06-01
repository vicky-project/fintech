@extends('fintech::layouts.app')

@section('title', 'Dashboard — FinTech')
@section('page_title', 'Dashboard')

@section('content')
@php
$symbol = $data['currency_details']['symbol'] ?? 'Rp';
$wallets = $data['wallets'] ?? [];
$totalBalance = $data['total_balance'] ?? 0;
$totalIncome = $data['total_income'] ?? 0;
$totalExpense = $data['total_expense'] ?? 0;
$incomeTrend = $data['trend']['income_change'] ?? 0;
$expenseTrend = $data['trend']['expense_change'] ?? 0;
$weeklyExpenses = $data['weekly_expense'] ?? [];
$recentTransactions = $data['recent_transactions'] ?? [];
$budgetWarnings = $data['budget_warnings'] ?? [];
$monthlyComparison = $monthlyComparison ?? [];
@endphp

{{-- Row 1: Stat Cards --}}
<div class="row g-3 mb-4">
  <div class="col-lg-4 col-md-6">
    <div class="card card-stat bg-primary text-white">
      <div class="card-body d-flex justify-content-between align-items-start">
        <div>
          <p class="mb-1 opacity-75 small fw-semibold text-uppercase">
            Total Saldo
          </p>
          <h3 class="mb-0 fw-bold">{{ $symbol }} {{ number_format($totalBalance, $data['currency_details']['precision'] ?? 0, ',', '.') }}</h3>
          <small class="opacity-75">{{ count($wallets) }} dompet aktif</small>
        </div>
        <i class="bi bi-wallet2 fs-1 opacity-50"></i>
      </div>
    </div>
  </div>

  <div class="col-lg-4 col-md-6">
    <div class="card card-stat text-white" style="background: linear-gradient(135deg, #10b981, #047857);">
      <div class="card-body d-flex justify-content-between align-items-start">
      <div>
      <p class="mb-1 opacity-75 small fw-semibold text-uppercase">Pemasukan Bulan Ini</p>
      <h3 class="mb-0 fw-bold">{{ $symbol }} {{ number_format($totalIncome, 0, ',', '.') }}</h3>
      @if($incomeTrend != 0)
      <small class="{{ $incomeTrend > 0 ? 'text-success-emphasis' : 'text-danger-emphasis' }}">
      {{ $incomeTrend > 0 ? '↑' : '↓' }} {{ abs($incomeTrend) }}% dari bulan lalu
      </small>
      @endif
      </div>
      <i class="bi bi-arrow-down-circle fs-1 opacity-50"></i>
      </div>
      </div>
      </div>

      <div class="col-lg-4 col-md-6">
      <div class="card card-stat text-white" style="background: linear-gradient(135deg, #ef4444, #b91c1c);">
      <div class="card-body d-flex justify-content-between align-items-start">
      <div>
      <p class="mb-1 opacity-75 small fw-semibold text-uppercase">Pengeluaran Bulan Ini</p>
      <h3 class="mb-0 fw-bold">{{ $symbol }} {{ number_format($totalExpense, 0, ',', '.') }}</h3>
      @if($expenseTrend != 0)
      <small class="{{ $expenseTrend < 0 ? 'text-success-emphasis' : 'text-warning-emphasis' }}">
      {{ $expenseTrend > 0 ? '↑' : '↓' }} {{ abs($expenseTrend) }}% dari bulan lalu
      </small>
      @endif
      </div>
      <i class="bi bi-arrow-up-circle fs-1 opacity-50"></i>
      </div>
      </div>
      </div>
      </div>

      {{-- Row 2: Charts --}}
      <div class="row g-3 mb-4">
      <div class="col-lg-6">
      <div class="card card-stat">
      <div class="card-header bg-white border-0 pt-3 px-3">
      <h6 class="mb-0 fw-semibold"><i class="bi bi-pie-chart me-2 text-primary"></i>Pengeluaran Mingguan</h6>
      </div>
      <div class="card-body">
      @if(count($weeklyExpenses) > 0)
      <div style="height: 220px;"><canvas id="weeklyChart"></canvas></div>
      @else
      <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox fs-1"></i>
      <p class="mt-2">Belum ada pengeluaran minggu ini</p>
      </div>
      @endif
      </div>
      </div>
      </div>

      <div class="col-lg-6">
      <div class="card card-stat">
      <div class="card-header bg-white border-0 pt-3 px-3">
      <h6 class="mb-0 fw-semibold"><i class="bi bi-bar-chart-line me-2 text-primary"></i>6 Bulan Terakhir</h6>
      </div>
      <div class="card-body">
      @if(count($monthlyComparison) > 0)
      <div style="height: 220px;"><canvas id="monthlyChart"></canvas></div>
      @else
      <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox fs-1"></i>
      <p class="mt-2">Belum ada data perbandingan</p>
      </div>
      @endif
      </div>
      </div>
      </div>
      </div>

      {{-- Row 3: Budget Warnings --}}
      @if(count($budgetWarnings) > 0)
      <div class="row g-3 mb-4">
      <div class="col-12">
      <div class="card card-stat border-start border-warning border-4">
      <div class="card-header bg-white border-0 pt-3 px-3">
      <h6 class="mb-0 fw-semibold"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Budget Perlu Perhatian</h6>
      </div>
      <div class="card-body pt-0">
      @foreach($budgetWarnings as $budget)
      <div class="d-flex align-items-center mb-2">
      <i class="{{ $budget['category']['icon'] ?? 'bi-tag' }} me-2" style="color: {{ $budget['category']['color'] ?? '#6c757d' }}"></i>
      <span class="flex-grow-1 small">{{ $budget['category']['name'] }}</span>
      <span class="small fw-semibold {{ $budget['percentage'] >= 100 ? 'text-danger' : 'text-warning' }} me-2">{{ $budget['percentage'] }}%</span>
      <div class="progress" style="width: 80px; height: 6px;">
      <div class="progress-bar {{ $budget['percentage'] >= 100 ? 'bg-danger' : 'bg-warning' }}" style="width: {{ min($budget['percentage'], 100) }}%"></div>
      </div>
      </div>
      @endforeach
      </div>
      </div>
      </div>
      </div>
      @endif

      {{-- Row 4: Recent Transactions --}}
      <div class="row g-3">
      <div class="col-12">
      <div class="card card-stat">
      <div class="card-header bg-white border-0 pt-3 px-3 d-flex justify-content-between align-items-center">
      <h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-2 text-primary"></i>Transaksi Terbaru</h6>
      <a href="{{ route('fintech.transactions.index') }}" class="btn btn-outline-primary btn-sm">Lihat Semua <i class="bi bi-arrow-right ms-1"></i></a>
      </div>
      <div class="card-body pt-0">
      @if(count($recentTransactions) > 0)
      <div class="table-responsive">
      <table class="table table-fintech table-hover mb-0">
      <thead>
      <tr>
      <th>Kategori</th>
      <th>Dompet</th>
      <th>Tanggal</th>
      <th class="text-end">Jumlah</th>
      </tr>
      </thead>
      <tbody>
      @foreach($recentTransactions as $trx)
      <tr class="cursor-pointer" onclick="window.location='{{ route('fintech.transactions.show', $trx['id']) }}'">
      <td>
      <i class="{{ $trx['category']['icon'] ?? 'bi-tag' }} me-2" style="color: {{ $trx['category']['color'] ?? '#6c757d' }}"></i>
      {{ $trx['category']['name'] }}
      </td>
      <td>{{ $trx['wallet']['name'] }}</td>
      <td>{{ \Carbon\Carbon::parse($trx['transaction_date'])->format('d M Y') }}</td>
      <td class="text-end fw-semibold {{ $trx['type'] === 'income' ? 'text-success' : 'text-danger' }}">
      {{ $trx['type'] === 'income' ? '' : '-' }}{{ $trx['formatted_amount'] }}
      </td>
      </tr>
      @endforeach
      </tbody>
      </table>
      </div>
      @else
      <div class="text-center py-4 text-muted">
      <i class="bi bi-receipt fs-1"></i>
      <p class="mt-2">Belum ada transaksi. Mulai catat keuangan Anda!</p>
      <a href="{{ route('fintech.transactions.create') }}" class="btn btn-primary btn-sm">
      <i class="bi bi-plus-lg me-1"></i>Tambah Transaksi
      </a>
      </div>
      @endif
      </div>
      </div>
      </div>
      </div>
      @endsection

      @push('scripts')
      <script>
      document.addEventListener('DOMContentLoaded', function() {
      // Weekly Doughnut Chart
      const weeklyData = @json($weeklyExpenses);
      if (weeklyData.length > 0) {
      new Chart(document.getElementById('weeklyChart'), {
      type: 'doughnut',
      data: {
      labels: weeklyData.map(d => d.label),
      datasets: [{
      data: weeklyData.map(d => d.value),
      backgroundColor: weeklyData.map(d => d.color || '#7986CB'),
      borderWidth: 0
      }]
      },
      options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
      legend: { position: 'bottom', labels: { padding: 15 } }
      }
      }
      });
      }

      // Monthly Bar Chart
      const monthlyData = @json($monthlyComparison);
      if (monthlyData.length > 0) {
      new Chart(document.getElementById('monthlyChart'), {
      type: 'bar',
      data: {
      labels: monthlyData.map(d => d.month),
      datasets: [
      {
      label: 'Pemasukan',
      data: monthlyData.map(d => d.income),
      backgroundColor: '#10b981',
      borderRadius: 4
      },
      {
      label: 'Pengeluaran',
      data: monthlyData.map(d => d.expense),
      backgroundColor: '#ef4444',
      borderRadius: 4
      }
      ]
      },
      options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
      legend: { position: 'bottom', labels: { padding: 15 } }
      },
      scales: {
      x: { grid: { display: false } },
      y: { beginAtZero: true, grid: { color: '#e2e8f0' } }
      }
      }
      });
      }
      });
      </script>
      @endpush