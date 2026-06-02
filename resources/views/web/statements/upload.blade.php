@extends('fintech::layouts.app')

@section('title', 'Upload Statement — FinTech')
@section('page_title', 'Upload Statement Bank')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card card-stat">
      <div class="card-body">
        <form method="POST" action="{{ route('fintech.statements.store') }}" enctype="multipart/form-data">
          @csrf
          <div class="mb-3">
            <label class="form-label">File Statement (PDF, Excel, CSV)</label>
            <input type="file" name="file" class="form-control" required accept=".pdf,.xls,.xlsx,.csv">
            <small class="text-muted">Maksimal 10MB</small>
          </div>
          <div class="mb-3">
            <label class="form-label">Password (jika file terproteksi)</label>
            <input type="password" name="password" class="form-control" placeholder="Opsional">
          </div>
          <div class="mb-3">
            <label class="form-label">Dompet Tujuan Import</label>
            <select name="wallet_id" class="form-select" required>
              @foreach($wallets as $w)
              <option value="{{ $w['id'] }}">{{ $w['name'] }} ({{ $w['currency']['code'] }})</option>
              @endforeach
            </select>
          </div>
          <button type="submit" class="btn btn-primary w-100">Proses Statement</button>
        </form>
      </div>
    </div>
  </div>
</div>
@endsection