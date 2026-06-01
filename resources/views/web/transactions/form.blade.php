@extends('fintech::layouts.app')

@section('title', isset($transaction) ? 'Edit Transaksi — FinTech' : 'Tambah Transaksi — FinTech')
@section('page_title', isset($transaction) ? 'Edit Transaksi' : 'Tambah Transaksi')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card card-stat">
      <div class="card-body">
        <form method="POST"
          action="{{ isset($transaction) ? route('fintech.transactions.update', $transaction->id) : route('fintech.transactions.store') }}">
          @csrf
          @if(isset($transaction)) @method('PUT') @endif

          <div class="mb-3">
            <label class="form-label">Dompet</label>
            <select name="wallet_id" class="form-select" required {{ isset($transaction) ? 'disabled' : '' }}>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}"
                {{ old('wallet_id', $transaction->wallet_id ?? '') == $w['id'] ? 'selected' : '' }}>
                {{ $w['name'] }} ({{ $w['currency']['code'] }})
              </option>
              @endforeach
            </select>
            @if(isset($transaction))
            <small class="text-muted">Dompet tidak dapat diubah.</small>
            <input type="hidden" name="wallet_id" value="{{ $transaction->wallet_id }}">
            @endif
          </div>
          <div class="mb-3">
            <label class="form-label">Tipe</label>
            <select name="type" class="form-select" required>
              <option value="income" {{ old('type', $transaction->type->value ?? '') === 'income' ? 'selected' : '' }}>Pemasukan</option>
              <option value="expense" {{ old('type', $transaction->type->value ?? '') === 'expense' ? 'selected' : '' }}>Pengeluaran</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Kategori</label>
            <select name="category_id" class="form-select" required>
              @foreach($categories as $cat)
              <option value="{{ $cat->id }}"
                {{ old('category_id', $transaction->category_id ?? '') == $cat->id ? 'selected' : '' }}>
                {{ $cat->name }}
              </option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required
            value="{{ old('amount', isset($transaction) ? $transaction->getAmountFloat() : '') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal</label>
            <input type="date" name="transaction_date" class="form-control" required
            value="{{ old('transaction_date', isset($transaction) ? $transaction->transaction_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi</label>
            <input type="text" name="description" class="form-control"
            value="{{ old('description', $transaction->description ?? '') }}" placeholder="Catatan (opsional)">
          </div>

          <div class="d-flex gap-2">
            <a href="{{ route('fintech.transactions.index') }}" class="btn btn-light">Batal</a>
            <button type="submit" class="btn btn-primary flex-grow-1">
              {{ isset($transaction) ? 'Simpan' : 'Tambah' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection