@extends('fintech::layouts.app')

@section('title', 'Notifikasi — FinTech')
@section('page_title', 'Notifikasi')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <form method="POST" action="{{ route('fintech.notifications.read-all') }}">
    @csrf
    <button type="submit" class="btn btn-sm btn-outline-primary" {{ count($notifications) === 0 ? 'disabled' : '' }}>
      <i class="bi bi-check-all me-1"></i>Tandai Semua Dibaca
    </button>
  </form>
</div>

@if(count($notifications) > 0)
<div class="list-group">
  @foreach($notifications as $n)
  <div class="list-group-item {{ $n['is_read'] ? '' : 'list-group-item-light border-start border-primary border-3' }}">
    <div class="d-flex justify-content-between">
      <div>
        <strong>{{ $n['title'] }}</strong>
        <p class="mb-1 small">
          {{ $n['message'] }}
        </p>
        <small class="text-muted">{{ \Carbon\Carbon::parse($n['created_at'])->diffForHumans() }}</small>
      </div>
      @if(!$n['is_read'])
      <form method="POST" action="{{ route('fintech.notifications.read', $n['id']) }}">
        @csrf
        <button class="btn btn-sm btn-link text-primary">Tandai Dibaca</button>
      </form>
      @endif
    </div>
  </div>
  @endforeach
</div>
@else
<div class="text-center py-5 text-muted">
  Tidak ada notifikasi.
</div>
@endif
@endsection