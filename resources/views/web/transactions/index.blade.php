@extends('fintech::layouts.app')

@section('title', 'Transaksi — FinTech')
@section('page_title', 'Daftar Transaksi')

@section('content')
@php
$transactions = $result['data'] ?? [];
$summary = $result['summary'] ?? ['total' => 0, 'income' => 0, 'expense' => 0];
$pagination = $result['pagination'] ?? ['current_page' => 1, 'last_page' => 1, 'total' => 0];
$wallets = $wallets ?? [];
$filters = $filters ?? ['wallet_id' => '', 'type' => '', 'month' => ''];
$symbol = $symbol ?? 'Rp';
@endphp

{{-- Statistik Ringkasan --}}
<div class="row g-2 mb-3">
  <div class="col-4">
    <div class="card card-stat bg-light">
      <div class="card-body text-center py-2 px-1">
        <small class="text-muted d-block">Total</small>
        <strong class="fs-5">{{ $summary['total'] }}</strong>
        <small class="text-muted d-block">transaksi</small>
      </div>
    </div>
  </div>
  <div class="col-4">
    <div class="card card-stat bg-light">
      <div class="card-body text-center py-2 px-1">
        <small class="text-success d-block">Masuk</small>
        <strong class="fs-6 text-success">{{ $symbol }}{{ number_format($summary['income'], 0, ',', '.') }}</strong>
      </div>
    </div>
  </div>
  <div class="col-4">
    <div class="card card-stat bg-light">
      <div class="card-body text-center py-2 px-1">
        <small class="text-danger d-block">Keluar</small>
        <strong class="fs-6 text-danger">{{ $symbol }}{{ number_format($summary['expense'], 0, ',', '.') }}</strong>
      </div>
    </div>
  </div>
</div>

{{-- Filter + Tombol --}}
<div class="card card-stat mb-3">
  <div class="card-body py-2 px-3">
    <form method="GET" action="{{ route('fintech.transactions.index') }}" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Dompet</label>
        <select name="wallet_id" class="form-select form-select-sm">
          <option value="">Semua Dompet</option>
          @foreach($wallets as $w)
          <option value="{{ $w['id'] }}" {{ $filters['wallet_id'] == $w['id'] ? 'selected' : '' }}>{{ $w['name'] }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold">Tipe</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">Semua</option>
          <option value="income" {{ $filters['type'] === 'income' ? 'selected' : '' }}>Pemasukan</option>
          <option value="expense" {{ $filters['type'] === 'expense' ? 'selected' : '' }}>Pengeluaran</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold">Bulan</label>
        <input type="month" name="month" class="form-control form-control-sm" value="{{ $filters['month'] }}">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary btn-sm w-100">
          <i class="bi bi-funnel me-1"></i>Filter
        </button>
      </div>
      <div class="col-md-3 text-md-end">
        <a href="{{ route('fintech.transactions.create') }}" class="btn btn-success btn-sm">
          <i class="bi bi-plus-lg me-1"></i>Tambah
        </a>
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#bulkDeleteModal">
          <i class="bi bi-calendar-x me-1"></i>Hapus Massal
        </button>
      </div>
    </form>
  </div>
</div>

{{-- Tabel Transaksi --}}
<div class="card card-stat">
  <div class="card-body p-0">
    @if(count($transactions) > 0)
    <div class="table-responsive">
      <table class="table table-fintech table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>Tanggal</th>
            <th>Kategori</th>
            <th>Deskripsi</th>
            <th>Dompet</th>
            <th class="text-end">Jumlah</th>
            <th class="text-center">Aksi</th>
          </tr>
        </thead>
        <tbody>
          @foreach($transactions as $trx)
          <tr>
            <td class="small">{{ \Carbon\Carbon::parse($trx['transaction_date'])->format('d/m/Y') }}</td>
            <td>
              <span class="badge rounded-pill d-inline-flex align-items-center" style="background-color: {{ $trx['category']['color'] ?? '#6c757d' }}20; color: {{ $trx['category']['color'] ?? '#6c757d' }}; border: 1px solid {{ $trx['category']['color'] ?? '#6c757d' }}40;">
                <i class="{{ $trx['category']['icon'] ?? 'bi-tag' }} me-1"></i>{{ $trx['category']['name'] }}
              </span>
            </td>
            <td class="small text-muted text-truncate" style="max-width: 200px;">{{ $trx['description'] ?? '-' }}</td>
            <td class="small">{{ $trx['wallet']['name'] }}</td>
            <td class="text-end fw-semibold {{ $trx['type'] === 'income' ? 'text-success' : 'text-danger' }}">
              {{ $trx['type'] === 'income' ? '+' : '-' }}{{ $trx['formatted_amount'] }}
            </td>
            <td class="text-center">
              <div class="dropdown">
                <button class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                  <i class="bi bi-three-dots"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <li><a class="dropdown-item" href="{{ route('fintech.transactions.show', $trx['id']) }}"><i class="bi bi-eye me-2"></i>Detail</a></li>
                  <li><a class="dropdown-item" href="{{ route('fintech.transactions.edit', $trx['id']) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form action="{{ route('fintech.transactions.destroy', $trx['id']) }}" method="POST" onsubmit="return confirm('Pindahkan ke tempat sampah?')">
                      @csrf @method('DELETE')
                      <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus</button>
                    </form>
                  </li>
                </ul>
              </div>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    @else
    <div class="text-center py-5 text-muted">
      <i class="bi bi-inbox fs-1"></i>
      <p class="mt-2">
        Tidak ada transaksi ditemukan
      </p>
    </div>
    @endif
  </div>
</div>

{{-- Pagination --}}
@if($pagination['last_page'] > 1)
<div class="d-flex justify-content-center mt-3">
  <nav>
    <ul class="pagination pagination-sm">
      @for($i = 1; $i <= $pagination['last_page']; $i++)
      <li class="page-item {{ $i == $pagination['current_page'] ? 'active' : '' }}">
        <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $i]) }}">{{ $i }}</a>
      </li>
      @endfor
    </ul>
  </nav>
</div>
@endif

{{-- Bulk Delete Modal --}}
<div class="modal fade" id="bulkDeleteModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="{{ route('fintech.transactions.bulk-destroy') }}" method="POST">
        @csrf
        <div class="modal-header">
          <h5 class="modal-title">Hapus Massal</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-danger small">
            <i class="bi bi-exclamation-triangle me-1"></i>Semua transaksi pada dompet dan bulan terpilih akan dipindahkan ke tempat sampah.
          </p>
          <div class="mb-3">
            <label class="form-label">Dompet</label>
            <select name="wallet_id" class="form-select" required>
              <option value="">Pilih Dompet</option>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}">{{ $w['name'] }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Bulan</label>
            <input type="month" name="month" class="form-control" required value="{{ now()->format('Y-m') }}">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
          <button type="submit" class="btn btn-danger">Hapus</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endsection