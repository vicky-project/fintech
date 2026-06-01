@extends('fintech::layouts.app')

@section('title', 'Budget — FinTech')
@section('page_title', 'Budget Saya')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <a href="{{ route('fintech.budgets.create') }}" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Tambah Budget
  </a>
</div>

@if(count($budgets) > 0)
<div class="row g-3">
  @foreach($budgets as $b)
  <div class="col-md-6">
    <div class="card card-stat">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
          <div>
            <i class="{{ $b['category']['icon'] }} me-2" style="color:{{ $b['category']['color'] }}"></i>
            <span class="fw-semibold">{{ $b['category']['name'] }}</span>
            @if($b['wallet'])
            <small class="d-block text-muted">{{ $b['wallet']['name'] }}</small>
            @endif
            <small class="text-muted">{{ $b['period_label'] }}</small>
          </div>
          <div class="dropdown">
            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
            <ul class="dropdown-menu dropdown-menu-end">
              <li><a class="dropdown-item" href="{{ route('fintech.budgets.edit', $b['id']) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
              <li>
                <form action="{{ route('fintech.budgets.destroy', $b['id']) }}" method="POST" onsubmit="return confirm('Hapus budget ini?')">
                  @csrf @method('DELETE')
                  <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus</button>
                </form>
              </li>
            </ul>
          </div>
        </div>
        <div class="mt-2">
          <div class="d-flex justify-content-between small mb-1">
            <span>{{ $b['formatted_spending'] }} / {{ $b['formatted_amount'] }}</span>
            <span class="{{ $b['is_overspent'] ? 'text-danger' : ($b['is_near_limit'] ? 'text-warning' : '') }}">
              {{ $b['percentage'] }}%
            </span>
          </div>
          <div class="progress" style="height: 8px;">
            <div class="progress-bar {{ $b['is_overspent'] ? 'bg-danger' : ($b['is_near_limit'] ? 'bg-warning' : 'bg-success') }}"
              style="width: {{ min($b['percentage'], 100) }}%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  @endforeach
</div>
@else
<div class="text-center py-5 text-muted">
  Belum ada budget.
</div>
@endif
@endsection