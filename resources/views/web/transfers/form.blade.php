@extends('fintech::layouts.app')

@section('title', isset($transfer) ? 'Edit Transfer — FinTech' : 'Tambah Transfer — FinTech')
@section('page_title', isset($transfer) ? 'Edit Transfer' : 'Tambah Transfer')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card card-stat">
      <div class="card-body">
        <form method="POST"
          action="{{ isset($transfer) ? route('fintech.transfers.update', $transfer->id) : route('fintech.transfers.store') }}">
          @csrf
          @if(isset($transfer)) @method('PUT') @endif

          <div class="mb-3">
            <label class="form-label">Dompet Asal</label>
            <select name="from_wallet_id" class="form-select" required {{ isset($transfer) ? 'disabled' : '' }}>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}"
                {{ old('from_wallet_id', $transfer->from_wallet_id ?? '') == $w['id'] ? 'selected' : '' }}>
                {{ $w['name'] }} ({{ $w['currency']['code'] }} — {{ $w['formatted_balance'] }})
              </option>
              @endforeach
            </select>
            @if(isset($transfer))
            <small class="text-muted">Dompet asal tidak dapat diubah.</small>
            <input type="hidden" name="from_wallet_id" value="{{ $transfer->from_wallet_id }}">
            @endif
          </div>
          <div class="mb-3">
            <label class="form-label">Dompet Tujuan</label>
            <select name="to_wallet_id" class="form-select" required {{ isset($transfer) ? 'disabled' : '' }}>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}"
                {{ old('to_wallet_id', $transfer->to_wallet_id ?? '') == $w['id'] ? 'selected' : '' }}>
                {{ $w['name'] }} ({{ $w['currency']['code'] }})
              </option>
              @endforeach
            </select>
            @if(isset($transfer))
            <input type="hidden" name="to_wallet_id" value="{{ $transfer->to_wallet_id }}">
            @endif
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah</label>
            <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required
            value="{{ old('amount', isset($transfer) ? $transfer->getAmountFloat() : '') }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Tanggal</label>
            <input type="date" name="transfer_date" class="form-control" required
            value="{{ old('transfer_date', isset($transfer) ? $transfer->transfer_date->format('Y-m-d') : now()->format('Y-m-d')) }}">
          </div>
          <div class="mb-3">
            <label class="form-label">Deskripsi</label>
            <input type="text" name="description" class="form-control"
            value="{{ old('description', $transfer->description ?? '') }}">
          </div>

          <div class="d-flex gap-2">
            <a href="{{ route('fintech.transfers.index') }}" class="btn btn-light">Batal</a>
            <button type="submit" class="btn btn-primary flex-grow-1">
              {{ isset($transfer) ? 'Simpan' : 'Transfer' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection