@extends('fintech::layouts.app')

@section('title', 'Zakat & Pajak — FinTech')
@section('page_title', 'Zakat & Pajak Penghasilan')

@section('content')

{{-- Tampilkan error jika ada --}}
@if(isset($error))
<div class="alert alert-warning d-flex align-items-center">
  <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
  <div>
    {!! $error !!}
  </div>
</div>
@endif

{{-- Jika data tidak tersedia (null) dan tidak ada error spesifik --}}
@if(empty($data) && !isset($error))
<div class="text-center py-5 text-muted">
  <i class="bi bi-inbox fs-1"></i>
  <p class="mt-2">
    Tidak dapat memuat data zakat dan pajak.
  </p>
</div>
@elseif(!empty($data))
@php $symbol = 'Rp'; @endphp

{{-- Pilih Tahun --}}
<form method="GET" class="row g-2 mb-4">
  <div class="col-auto">
    <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
      @for($y = now()->year; $y >= 2020; $y--)
      <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
      @endfor
    </select>
  </div>
</form>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card card-stat text-center">
      <div class="card-body">
        <h6>Total Kekayaan</h6>
        <h3>{{ $symbol }} {{ number_format($data['total_wealth'], 0, ',', '.') }}</h3>
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card card-stat text-center">
      <div class="card-body">
        <h6>Pemasukan Tahunan</h6>
        <h3>{{ $symbol }} {{ number_format($data['yearly_income'], 0, ',', '.') }}</h3>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-2">
  <div class="col-md-6">
    <div class="card card-stat">
      <div class="card-body">
        <h6>Zakat Mal</h6>
        @if($data['zakat_mal']['eligible'])
        <p class="text-success">
          Wajib: {{ $symbol }} {{ number_format($data['zakat_mal']['amount'], 0, ',', '.') }}
        </p>
        @else
        <p>
          Belum mencapai nisab ({{ $symbol }} {{ number_format($data['nisab'], 0, ',', '.') }})
        </p>
        @endif
      </div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="card card-stat">
      <div class="card-body">
        <h6>Zakat Penghasilan</h6>
        @if($data['zakat_income']['eligible'])
        <p class="text-success">
          Wajib: {{ $symbol }} {{ number_format($data['zakat_income']['amount'], 0, ',', '.') }}
        </p>
        @else
        <p>
          Belum mencapai nisab
        </p>
        @endif
      </div>
    </div>
  </div>
</div>

<div class="card card-stat mt-3">
  <div class="card-body">
    <h6>Estimasi PPh</h6>
    <p>
      PTKP: {{ $symbol }} {{ number_format($data['income_tax']['ptkp'], 0, ',', '.') }}
    </p>
    <p>
      PKP: {{ $symbol }} {{ number_format($data['income_tax']['pkp'], 0, ',', '.') }}
    </p>
    <h5 class="text-danger">PPh Terutang: {{ $symbol }} {{ number_format($data['income_tax']['tax'], 0, ',', '.') }}</h5>
  </div>
</div>

@if(!empty($data['historical_tax']))
<div class="card card-stat mt-3">
  <div class="card-header bg-white">
    <h6 class="mb-0">Riwayat Pajak</h6>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead>
        <tr><th>Tahun</th><th>Penghasilan</th><th>PTKP</th><th>PKP</th><th>PPh</th></tr>
      </thead>
      <tbody>
        @foreach($data['historical_tax'] as $h)
        <tr>
          <td>{{ $h['year'] }}</td>
          <td>{{ $symbol }} {{ number_format($h['income'], 0, ',', '.') }}</td>
          <td>{{ $symbol }} {{ number_format($h['ptkp'], 0, ',', '.') }}</td>
          <td>{{ $symbol }} {{ number_format($h['pkp'], 0, ',', '.') }}</td>
          <td>{{ $symbol }} {{ number_format($h['tax'], 0, ',', '.') }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</div>
@endif
@endif

@endsection