@extends('fintech::layouts.app')

@section('title', isset($budget) ? 'Edit Budget — FinTech' : 'Tambah Budget — FinTech')
@section('page_title', isset($budget) ? 'Edit Budget' : 'Tambah Budget')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card card-stat">
      <div class="card-body">
        <form method="POST"
          action="{{ isset($budget) ? route('fintech.budgets.update', $budget->id) : route('fintech.budgets.store') }}">
          @csrf
          @if(isset($budget)) @method('PUT') @endif

          <div class="mb-3">
            <label class="form-label">Kategori</label>
            <select name="category_id" class="form-select" required>
              @foreach($categories as $cat)
              <option value="{{ $cat->id }}"
                {{ old('category_id', $budget->category_id ?? '') == $cat->id ? 'selected' : '' }}>
                {{ $cat->name }}
              </option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Dompet (opsional, kosongkan untuk semua)</label>
            <select name="wallet_id" class="form-select">
              <option value="">Semua Dompet</option>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}"
                {{ old('wallet_id', $budget->wallet_id ?? '') == $w['id'] ? 'selected' : '' }}>
                {{ $w['name'] }}
              </option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah Budget</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required
            value="{{ old('amount', isset($budget) ? $budget->getAmountFloat() : '') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Periode</label>
            <select name="period_type" class="form-select" required>
              <option value="monthly" {{ old('period_type', $budget->period_type->value ?? '') === 'monthly' ? 'selected' : '' }}>Bulanan</option>
              <option value="yearly" {{ old('period_type', $budget->period_type->value ?? '') === 'yearly' ? 'selected' : '' }}>Tahunan</option>
            </select>
          </div>

          <div class="d-flex gap-2">
            <a href="{{ route('fintech.budgets.index') }}" class="btn btn-light">Batal</a>
            <button type="submit" class="btn btn-primary flex-grow-1">
              {{ isset($budget) ? 'Simpan' : 'Buat Budget' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection