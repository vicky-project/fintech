@extends('fintech::layouts.app')

@section('title', 'Insight — FinTech')
@section('page_title', 'Analisis Keuangan')

@section('content')
@if(empty($analysis))
<div class="text-center py-5 text-muted">
  Tidak ada data analisis.
</div>
@else
@php $symbol = $analysis['currency'] ?? 'Rp'; @endphp

{{-- Trend --}}
<div class="card card-stat mb-3">
  <div class="card-body">
    <h5>Pengeluaran Bulan Ini</h5>
    <h3>{{ $symbol }} {{ number_format($analysis['trend']['current_month_total'], 0, ',', '.') }}</h3>
    <span class="{{ $analysis['trend']['change_percentage'] > 0 ? 'text-danger' : 'text-success' }}">
      {{ $analysis['trend']['change_percentage'] > 0 ? '↑' : '↓' }} {{ abs($analysis['trend']['change_percentage']) }}% dari bulan lalu
    </span>
  </div>
</div>

{{-- Budgets --}}
@if(!empty($analysis['budgets']))
<div class="card card-stat mb-3">
  <div class="card-header bg-white">
    <h6 class="mb-0">Status Budget</h6>
  </div>
  <div class="list-group list-group-flush">
    @foreach($analysis['budgets'] as $b)
    <div class="list-group-item">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <i class="{{ $b['category']['icon'] }}" style="color:{{ $b['category']['color'] }}"></i> {{ $b['category']['name'] }}
          @if($b['wallet'])<small class="text-muted">({{ $b['wallet']['name'] }})</small>@endif
        </div>
        <span class="{{ $b['is_overspent'] ? 'text-danger' : ($b['is_near_limit'] ? 'text-warning' : '') }}">{{ $b['percentage'] }}%</span>
      </div>
      <div class="progress mt-1" style="height:6px">
        <div class="progress-bar bg-{{ $b['is_overspent'] ? 'danger' : ($b['is_near_limit'] ? 'warning' : 'success') }}" style="width:{{ min($b['percentage'],100) }}%"></div>
      </div>
    </div>
    @endforeach
  </div>
</div>
@endif

{{-- Recommendations --}}
@if(!empty($analysis['recommendations']))
<div class="card card-stat mb-3">
  <div class="card-header bg-white">
    <h6 class="mb-0">Rekomendasi</h6>
  </div>
  <div class="card-body">
    @foreach($analysis['recommendations'] as $rec)
    <div class="alert alert-{{ $rec['type'] == 'warning' ? 'warning' : ($rec['type'] == 'success' ? 'success' : 'info') }} py-2">
      <i class="bi {{ $rec['icon'] }} me-2"></i><strong>{{ $rec['title'] }}</strong><br><small>{{ $rec['message'] }}</small>
    </div>
    @endforeach
  </div>
</div>
@endif

{{-- Anomalies, Subscriptions, Projection, dll. dapat ditambahkan serupa --}}
@endif
@endsection