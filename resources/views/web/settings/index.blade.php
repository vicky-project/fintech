@extends('fintech::layouts.app')

@section('title', 'Pengaturan — FinTech')
@section('page_title', 'Pengaturan')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-8">
    <form method="POST" action="{{ route('fintech.settings.update') }}">
      @csrf @method('PUT')
      <div class="card card-stat mb-3">
        <div class="card-header bg-white">
          <h6 class="mb-0">Preferensi</h6>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Mata Uang Default</label>
            <select name="default_currency" class="form-select">
              <option value="IDR" {{ ($settings->default_currency ?? '') === 'IDR' ? 'selected' : '' }}>IDR</option>
              <option value="USD" {{ ($settings->default_currency ?? '') === 'USD' ? 'selected' : '' }}>USD</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Dompet Default</label>
            <select name="default_wallet_id" class="form-select">
              <option value="">Tidak Ada</option>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}" {{ ($settings->default_wallet_id ?? '') == $w['id'] ? 'selected' : '' }}>{{ $w['name'] }}</option>
              @endforeach
            </select>
          </div>
        </div>
      </div>

      <div class="card card-stat mb-3">
        <div class="card-header bg-white">
          <h6 class="mb-0">Data Perpajakan</h6>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label">Status Perkawinan</label>
            <select name="marital_status" class="form-select">
              @foreach($maritalStatuses as $val => $label)
              <option value="{{ $val }}" {{ ($settings->marital_status->value ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jumlah Tanggungan</label>
            <input type="number" name="dependents" class="form-control" min="0" max="10" value="{{ $settings->dependents ?? 0 }}">
          </div>
        </div>
      </div>

      <div class="card card-stat mb-3">
        <div class="card-header bg-white">
          <h6 class="mb-0">Notifikasi</h6>
        </div>
        <div class="card-body">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notification_telegram" value="1" {{ ($settings->preferences['notification_telegram'] ?? false) ? 'checked' : '' }}>
            <label class="form-check-label">Notifikasi Telegram</label>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-primary w-100">Simpan Pengaturan</button>
    </form>
  </div>
</div>
@endsection