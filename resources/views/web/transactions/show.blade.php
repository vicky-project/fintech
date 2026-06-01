@extends('fintech::layouts.app')

@section('title', 'Detail Transaksi — FinTech')
@section('page_title', 'Detail Transaksi')

@section('content')
<div class="row justify-content-center">
  <div class="col-lg-6">
    <div class="card card-stat">
      <div class="card-body text-center">
        <i class="{{ $transaction->category->icon }} fs-1" style="color:{{ $transaction->category->color }}"></i>
        <h4 class="mt-2">{{ $transaction->category->name }}</h4>
        <span class="badge bg-secondary">{{ $transaction->type->label() }}</span>
      </div>
      <div class="card-body">
        <table class="table table-sm">
          <tr><th>Jumlah</th><td class="fw-bold {{ $transaction->type->value === 'income' ? 'text-success' : 'text-danger' }}">
            {{ $transaction->type->value === 'income' ? '+' : '-' }}{{ $transaction->getFormattedAmount() }}</td></tr>
          <tr><th>Dompet</th><td>{{ $transaction->wallet->name }}</td></tr>
          <tr><th>Tanggal</th><td>{{ $transaction->transaction_date->translatedFormat('d F Y') }}</td></tr>
          <tr><th>Deskripsi</th><td>{{ $transaction->description ?? '-' }}</td></tr>
        </table>
        <div class="d-flex gap-2 mt-3">
          <a href="{{ route('fintech.transactions.edit', $transaction->id) }}" class="btn btn-outline-primary w-50">Edit</a>
          <form action="{{ route('fintech.transactions.destroy', $transaction->id) }}" method="POST" class="w-50"
            onsubmit="return confirm('Pindahkan ke tempat sampah?')">
            @csrf @method('DELETE')
            <button class="btn btn-outline-danger w-100">Hapus</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection