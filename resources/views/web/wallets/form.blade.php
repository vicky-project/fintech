@extends('fintech::layouts.app')

@section('title', isset($wallet) ? 'Edit Dompet — FinTech' : 'Tambah Dompet — FinTech')
@section('page_title', isset($wallet) ? 'Edit Dompet' : 'Tambah Dompet')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card card-stat">
      <div class="card-body">
        <form method="POST"
          action="{{ isset($wallet) ? route('fintech.wallets.update', $wallet->id) : route('fintech.wallets.store') }}">
          @csrf
          @if(isset($wallet)) @method('PUT') @endif

          <div class="mb-3">
            <label class="form-label">Nama Dompet</label>
            <input type="text" name="name" class="form-control" required
            value="{{ old('name', $wallet->name ?? '') }}" placeholder="Contoh: Dompet Utama">
          </div>
          <div class="mb-3">
            <label class="form-label">Mata Uang</label>
            <select name="currency" class="form-select" required {{ isset($wallet) ? 'disabled' : '' }}>
              <option value="IDR" {{ (old('currency', $wallet->currency ?? '') === 'IDR') ? 'selected' : '' }}>IDR - Indonesian Rupiah</option>
              <option value="USD" {{ (old('currency', $wallet->currency ?? '') === 'USD') ? 'selected' : '' }}>USD - US Dollar</option>
            </select>
            @if(isset($wallet))
            <small class="text-muted">Mata uang tidak dapat diubah setelah dompet dibuat.</small>
            <input type="hidden" name="currency" value="{{ $wallet->currency }}">
            @endif
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi (opsional)</label>
            <input type="text" name="description" class="form-control"
            value="{{ old('description', $wallet->description ?? '') }}" placeholder="Keterangan singkat">
          </div>
          @if(!isset($wallet))
          <div class="mb-3">
            <label class="form-label">Saldo Awal</label>
            <input type="number" name="initial_balance" class="form-control" step="0.01" min="0"
            value="{{ old('initial_balance', 0) }}">
          </div>
          @endif
          @if(isset($wallet))
          <div class="mb-3 form-check">
            <input type="checkbox" name="is_active" value="1" class="form-check-input"
            {{ old('is_active', $wallet->is_active) ? 'checked' : '' }}>
            <label class="form-check-label">Dompet Aktif</label>
          </div>
          @endif

          <div class="d-flex gap-2">
            <a href="{{ route('fintech.wallets.index') }}" class="btn btn-light">Batal</a>
            <button type="submit" class="btn btn-primary flex-grow-1">
              {{ isset($wallet) ? 'Simpan Perubahan' : 'Buat Dompet' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection