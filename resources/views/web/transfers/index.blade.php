@extends('fintech::layouts.app')

@section('title', 'Transfer — FinTech')
@section('page_title', 'Daftar Transfer')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <a href="{{ route('fintech.transfers.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Tambah Transfer
  </a>
</div>

{{-- Filter --}}
<form method="GET" class="row g-2 mb-3">
  <div class="col-md-3">
    <select name="wallet_id" class="form-select form-select-sm">
      <option value="">Semua Dompet</option>
      @foreach($wallets as $w)
      <option value="{{ $w['id'] }}" {{ ($filters['wallet_id'] ?? '') == $w['id'] ? 'selected' : '' }}>
        {{ $w['name'] }}
      </option>
      @endforeach
    </select>
  </div>
  <div class="col-md-2">
    <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
  </div>
</form>

{{-- Daftar Transfer --}}
@php
$transferList = $transfers ?? [];

if (is_object($transferList)) {
if ($transferList instanceof \Illuminate\Support\Collection) {
$transferList = $transferList->all();
} elseif (method_exists($transferList, 'items')) {
$transferList = $transferList->items();
} elseif (method_exists($transferList, 'toArray')) {
$transferList = $transferList->toArray();
} else {
$transferList = (array) $transferList;
}
}

$transferList = is_array($transferList) ? $transferList : [];
@endphp

@if(count($transferList) > 0)
<div class="row g-3">
  @foreach($transferList as $t)
  <div class="col-md-6">
    <div class="card card-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between">
          <div>
            <i class="bi bi-arrow-right me-2 text-primary"></i>
            {{ $t['from_wallet']['name'] ?? '?' }} → {{ $t['to_wallet']['name'] ?? '?' }}
          </div>
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="{{ route('fintech.transfers.edit', $t['id']) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
              <li>
                <form action="{{ route('fintech.transfers.destroy', $t['id']) }}" method="POST" onsubmit="return confirm('Pindahkan ke tempat sampah?')">
                  @csrf @method('DELETE')
                  <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus</button>
                </form>
              </li>
            </ul>
          </div>
        </div>
        <h5 class="mt-2 text-primary">{{ $t['formatted_amount'] ?? '0' }}</h5>
        <small class="text-muted">{{ \Carbon\Carbon::parse($t['transfer_date'] ?? now())->format('d M Y') }}</small>
        @if(!empty($t['description']))
        <p class="mt-1 small text-muted">
          {{ $t['description'] }}
        </p>
        @endif
      </div>
    </div>
  </div>
  @endforeach
</div>
@else
<div class="text-center py-5 text-muted">
  Belum ada transfer.
</div>
@endif

@if(isset($pagination))
@include('fintech::partials.pagination', ['pagination' => $pagination])
@endif
@endsection