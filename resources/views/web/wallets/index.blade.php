@extends('fintech::layouts.app')

@section('title', 'Dompet — FinTech')
@section('page_title', 'Dompet Saya')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
  <div></div>
  <a href="{{ route('fintech.wallets.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Tambah Dompet
  </a>
</div>

@if(count($wallets) > 0)
<div class="row g-3">
  @foreach($wallets as $wallet)
  <div class="col-md-6 col-lg-4">
    <div class="card card-stat h-100">
      <div class="card-body d-flex flex-column">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div>
            <i class="bi bi-wallet2 fs-4 text-primary me-2"></i>
            <span class="fw-semibold fs-5">{{ $wallet['name'] }}</span>
          </div>
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown">
              <i class="bi bi-three-dots"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="{{ route('fintech.wallets.edit', $wallet['id']) }}">
                <i class="bi bi-pencil me-2"></i>Edit</a></li>
              <li>
                <form action="{{ route('fintech.wallets.destroy', $wallet['id']) }}" method="POST"
                  onsubmit="return confirm('Hapus dompet ini? Semua transaksi terkait akan tetap ada.')">
                  @csrf @method('DELETE')
                  <button type="submit" class="dropdown-item text-danger">
                    <i class="bi bi-trash me-2"></i>Hapus</button>
                </form>
              </li>
            </ul>
          </div>
        </div>
        <h3 class="fw-bold mb-1">{{ $wallet['formatted_balance'] }}</h3>
        <small class="text-muted">{{ $wallet['currency']['code'] }} — {{ $wallet['description'] ?? 'Tidak ada deskripsi' }}</small>
        @if(!$wallet['is_active'])
        <span class="badge bg-warning mt-2 align-self-start">Nonaktif</span>
        @endif
      </div>
    </div>
  </div>
  @endforeach
</div>
@else
<div class="text-center py-5">
  <i class="bi bi-wallet2 display-1 text-muted"></i>
  <h4 class="mt-3">Belum Ada Dompet</h4>
  <p class="text-muted">
    Buat dompet pertama untuk mulai mencatat keuangan.
  </p>
  <a href="{{ route('fintech.wallets.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-circle me-1"></i>Buat Dompet
  </a>
</div>
@endif
@endsection