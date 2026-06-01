@extends('fintech::layouts.app')

@section('title', $wallet->name . ' — FinTech')
@section('page_title', 'Detail Dompet')

@section('content')
<div class="row">
  <div class="col-lg-6">
    <div class="card card-stat mb-3">
      <div class="card-body">
        <h4 class="fw-bold">{{ $wallet->name }}</h4>
        <h2 class="text-primary">{{ $detail['formatted_balance'] }}</h2>
        <p>
          {{ $detail['currency']['name'] }} ({{ $detail['currency']['code'] }})
        </p>
        <span class="badge {{ $detail['is_active'] ? 'bg-success' : 'bg-warning' }}">
          {{ $detail['is_active'] ? 'Aktif' : 'Nonaktif' }}
        </span>
        <p class="mt-2 text-muted">
          {{ $detail['description'] ?? 'Tidak ada deskripsi' }}
        </p>
        <small>Jumlah transaksi: {{ $detail['transaction_count'] }}</small>
      </div>
    </div>
  </div>
</div>
@endsection