@extends('fintech::layouts.app')

@section('title', 'Laporan — FinTech')
@section('page_title', 'Laporan Keuangan')

@section('content')
@php
$symbol = is_string($chartData['currency'] ?? null) ? $chartData['currency'] : 'IDR';
$totalIncome = array_sum($chartData['income'] ?? []);
$totalExpense = array_sum($chartData['expense'] ?? []);

// Ambil years, pastikan array biasa (bukan Collection atau objek lain)
$yearsRaw = $categoryTable['years'] ?? [];

if ($yearsRaw instanceof \Illuminate\Support\Collection) {
$years = $yearsRaw->toArray();
} elseif (is_array($yearsRaw)) {
$years = $yearsRaw;
} else {
$years = [];
}

// Konversi semua elemen jadi string agar aman dicetak
$years = array_map(function($y) {
return is_scalar($y) ? (string) $y : '';
}, $years);

$categories = $categoryTable['categories'] ?? [];
@endphp

{{-- Filter --}}
<form method="GET" class="row g-2 mb-4">
  <div class="col-md-2">
    <select name="wallet_id" class="form-select form-select-sm">
      <option value="">Semua Dompet</option>
      @foreach($wallets as $w)
      <option value="{{ $w['id'] }}" {{ ($walletId ?? '') == $w['id'] ? 'selected' : '' }}>
        {{ is_string($w['name'] ?? '') ? $w['name'] : '' }}
      </option>
      @endforeach
    </select>
  </div>
  <div class="col-md-2">
    <select name="period_type" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="monthly" {{ $periodType === 'monthly' ? 'selected' : '' }}>Bulanan</option>
      <option value="yearly" {{ $periodType === 'yearly' ? 'selected' : '' }}>Tahunan</option>
      <option value="all_years" {{ $periodType === 'all_years' ? 'selected' : '' }}>Semua Tahun</option>
    </select>
  </div>
  @if($periodType === 'monthly')
  <div class="col-md-2">
    <input type="month" name="month" class="form-control form-control-sm" value="{{ $year }}-{{ str_pad($month, 2, '0', STR_PAD_LEFT) }}">
  </div>
  @elseif($periodType === 'yearly')
  <div class="col-md-2">
    <input type="number" name="year" class="form-control form-control-sm" value="{{ $year }}" min="2000" max="{{ now()->year }}">
  </div>
  @endif
  <div class="col-md-2">
    <button type="submit" class="btn btn-sm btn-primary">Terapkan</button>
  </div>
</form>

{{-- Chart --}}
<div class="card card-stat mb-4">
  <div class="card-body">
    <div style="height: 250px;">
      <canvas id="reportChart"></canvas>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="card card-stat text-center">
      <div class="card-body">
        <small class="text-success">Total Pemasukan</small>
        <h4>{{ $symbol }} {{ number_format($totalIncome, 0, ',', '.') }}</h4>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card card-stat text-center">
      <div class="card-body">
        <small class="text-danger">Total Pengeluaran</small>
        <h4>{{ $symbol }} {{ number_format($totalExpense, 0, ',', '.') }}</h4>
      </div>
    </div>
  </div>
</div>

{{-- Category Summary Table --}}
<div class="card card-stat">
  <div class="card-header bg-white">
    <h6 class="mb-0">Distribusi Kategori</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Kategori</th>
            @foreach($years as $y)
            <th class="text-end">{{ $y }}</th>
            @endforeach
            <th class="text-end">Total</th>
          </tr>
        </thead>
        <tbody>
          @foreach($categories as $cat)
          <tr>
            <td>
              {{-- Pastikan icon adalah string --}}
              @if(is_string($cat['icon'] ?? null))
              <i class="{{ $cat['icon'] }}" style="color:{{ $cat['color'] ?? '#000' }}"></i>
              @endif
              {{ is_string($cat['name'] ?? '') ? $cat['name'] : '' }}
            </td>
            @foreach($years as $y)
            <td class="text-end">
              {{ $symbol }} {{ number_format(floatval($cat['data'][$y] ?? 0), 0, ',', '.') }}
            </td>
            @endforeach
            <td class="text-end fw-semibold">
              @php
              $rowTotal = array_sum(array_map('floatval', $cat['data'] ?? []));
              @endphp
              {{ $symbol }} {{ number_format($rowTotal, 0, ',', '.') }}
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection

@push('scripts')
<script>
  document.addEventListener('DOMContentLoaded', () => {
  const ctx = document.getElementById('reportChart')?.getContext('2d');
  if (ctx) {
  new Chart(ctx, {
  type: 'bar',
  data: {
  labels: @json($chartData['labels'] ?? []),
  datasets: [
  {
  label: 'Pemasukan',
  data: @json($chartData['income'] ?? []),
  backgroundColor: '#4DB6AC'
  },
  {
  label: 'Pengeluaran',
  data: @json($chartData['expense'] ?? []),
  backgroundColor: '#FF6384'
  }
  ]
  },
  options: {
  responsive: true,
  maintainAspectRatio: false
  }
  });
  }
  });
</script>
@endpush