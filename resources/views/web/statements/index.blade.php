@extends('fintech::layouts.app')

@section('title', 'Statement — FinTech')
@section('page_title', 'Riwayat Statement')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
  <div></div>
  <a href="{{ route('fintech.statements.create') }}" class="btn btn-primary">
    <i class="bi bi-cloud-upload me-1"></i>Upload Statement
  </a>
</div>

@if(count($statements) > 0)
<div class="list-group">
  @foreach($statements as $s)
  <div class="list-group-item">
    <div class="d-flex justify-content-between align-items-start">
      <div>
        <i class="bi bi-file-earmark-text me-2"></i>
        <span class="fw-semibold">{{ $s['original_filename'] }}</span>
        <div class="small text-muted">
          {{ $s['bank_code'] }} | {{ $s['wallet']['name'] ?? '-' }}
        </div>
        <span class="badge bg-{{ $s['status'] === 'imported' ? 'success' : ($s['status'] === 'parsed' ? 'warning' : ($s['status'] === 'failed' ? 'danger' : 'secondary')) }}">
          {{ $s['status_label'] }}
        </span>
        @if($s['remaining_count'] > 0)
        <span class="badge bg-warning ms-1">{{ $s['remaining_count'] }} belum diimpor</span>
        @endif
        <div class="small text-muted mt-1">
          {{ \Carbon\Carbon::parse($s['created_at'])->format('d M Y H:i') }}
        </div>
      </div>
      <div class="dropdown">
        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
        <ul class="dropdown-menu dropdown-menu-end">
          @if($s['status'] === 'parsed')
          <li><a href="{{ route('fintech.statements.show', $s['id']) }}" class="dropdown-item"><i class="bi bi-eye me-2"></i>Preview & Import</a></li>
          @endif
          <li>
            <form action="{{ route('fintech.statements.destroy', $s['id']) }}" method="POST" onsubmit="return confirm('Hapus permanen?')">
              @csrf @method('DELETE')
              <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Hapus</button>
            </form>
          </li>
        </ul>
      </div>
    </div>
  </div>
  @endforeach
</div>
<div class="mt-3">
  {{ $statements->links() }}
</div>
@else
<div class="text-center py-5 text-muted">
  Belum ada statement.
</div>
@endif
@endsection