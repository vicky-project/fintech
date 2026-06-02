@extends('fintech::layouts.app')

@section('title', 'Preview Statement — FinTech')
@section('page_title', 'Preview & Import Transaksi')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <h6>Dompet: {{ $preview['wallet']['name'] }}</h6>
  @if($preview['conversion'])
  <span class="badge bg-info">Konversi: {{ $preview['conversion']['from'] }} → {{ $preview['conversion']['to'] }} (rate: {{ $preview['conversion']['rate'] }})</span>
  @endif
</div>

@if(count($preview['transactions']) > 0)
<form method="POST" action="{{ route('fintech.statements.import', $id) }}">
  @csrf
  <div class="table-responsive mb-3">
    <table class="table table-sm">
      <thead>
        <tr>
          <th><input type="checkbox" id="selectAll" checked></th>
          <th>Tanggal</th>
          <th>Deskripsi</th>
          <th>Jumlah</th>
          <th>Kategori</th>
        </tr>
      </thead>
      <tbody>
        @foreach($preview['transactions'] as $trx)
        <tr>
          <td><input type="checkbox" name="transaction_ids[]" value="{{ $trx['id'] }}" checked></td>
          <td>{{ $trx['date'] }}</td>
          <td>{{ $trx['description'] }}</td>
          <td>{{ $trx['formatted_amount'] }} ({{ $trx['type_label'] }})</td>
          <td>
            <select name="categories[{{ $trx['id'] }}]" class="form-select form-select-sm" style="width:180px">
              @foreach($preview['categories'] as $cat)
              <option value="{{ $cat->id }}" {{ $trx['category']['id'] == $cat->id ? 'selected' : '' }}>
                {{ $cat->name }}
              </option>
              @endforeach
            </select>
          </td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <button type="submit" class="btn btn-success">Impor Transaksi Terpilih</button>
  <a href="{{ route('fintech.statements.index') }}" class="btn btn-light">Kembali</a>
</form>
@else
<div class="text-center py-5 text-muted">
  Tidak ada transaksi yang bisa diimpor.
</div>
@endif
@endsection

@push('scripts')
<script>
  document.getElementById('selectAll')?.addEventListener('change', function() {
  document.querySelectorAll('input[name="transaction_ids[]"]').forEach(cb => cb.checked = this.checked);
  });
</script>
@endpush