@extends('fintech::layouts.app')

@section('title', 'Export — FinTech')
@section('page_title', 'Ekspor Data Keuangan')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-8">
    <div class="card card-stat">
      <div class="card-body">
        <form method="POST" action="{{ route('fintech.export.process') }}">
          @csrf
          <div class="mb-3">
            <label class="form-label">Dompet <span class="text-danger">*</span></label>
            <select name="wallet_id" class="form-select" required>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}">{{ $w['name'] }} ({{ $w['currency']['code'] }})</option>
              @endforeach
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Jenis Data</label>
            <select name="type" class="form-select">
              <option value="transactions">Transaksi</option>
              <option value="transfers">Transfer</option>
              <option value="budgets">Budget</option>
              <option value="all">Semua Data</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Format</label>
            <select name="format" class="form-select">
              <option value="xlsx">Excel (.xlsx)</option>
              <option value="pdf">PDF</option>
              <option value="csv">CSV</option>
              <option value="gsheet">Google Sheets</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Rentang Tanggal (opsional)</label>
            <div class="row g-2">
              <div class="col">
                <input type="date" name="date_from" class="form-control">
              </div>
              <div class="col">
                <input type="date" name="date_to" class="form-control">
              </div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary w-100"><i class="bi bi-download me-1"></i>Ekspor</button>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection