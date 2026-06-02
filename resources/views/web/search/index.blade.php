@extends('fintech::layouts.app')

@section('title', 'Pencarian — FinTech')
@section('page_title', 'Pencarian')

@section('content')
<form method="GET" action="{{ route('fintech.search') }}" class="mb-4">
  <div class="input-group">
    <input type="search" name="q" class="form-control" placeholder="Cari transaksi, transfer, statement..." value="{{ $keyword }}">
    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
  </div>
</form>

@if(strlen($keyword) >= 2)
@if(count($results) > 0)
<div class="list-group">
  @foreach($results as $item)
  <div class="list-group-item">
    <div class="d-flex align-items-center">
      <i class="{{ $item['icon'] }} me-3 fs-5" style="color:{{ $item['color'] }}"></i>
      <div class="flex-grow-1">
        @if($item['type'] === 'transaction')
        <strong>{{ $item['category'] }}</strong> - {{ $item['description'] ?? 'Tanpa deskripsi' }}
        <small class="d-block text-muted">{{ $item['wallet'] }} · {{ \Carbon\Carbon::parse($item['date'])->format('d M Y') }}</small>
        @elseif($item['type'] === 'transfer')
        <strong>Transfer</strong>: {{ $item['description'] ?? 'Transfer' }}
        <small class="d-block text-muted">{{ \Carbon\Carbon::parse($item['date'])->format('d M Y') }}</small>
        @else
        <strong>Statement</strong>: {{ $item['description'] }}
        <small class="d-block text-muted">{{ $item['bank_code'] }} · {{ $item['status'] }}</small>
        @endif
      </div>
      @if(isset($item['amount']))
      <span class="fw-semibold {{ ($item['transaction_type'] ?? '') === 'income' ? 'text-success' : (($item['transaction_type'] ?? '') === 'expense' ? 'text-danger' : '') }}">
        {{ $item['amount'] }}
      </span>
      @endif
    </div>
  </div>
  @endforeach
</div>
@else
<div class="text-center py-5 text-muted">
  Tidak ditemukan hasil untuk "{{ $keyword }}".
</div>
@endif
@else
<div class="text-center py-5 text-muted">
  Ketik minimal 2 karakter untuk mencari.
</div>
@endif
@endsection