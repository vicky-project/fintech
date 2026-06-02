@if ($pagination['last_page'] > 1)
<nav class="d-flex justify-content-center mt-3">
  <ul class="pagination pagination-sm">
    @for ($i = 1; $i <= $pagination['last_page']; $i++)
    <li class="page-item {{ $i == $pagination['current_page'] ? 'active' : '' }}">
      <a class="page-link" href="{{ request()->fullUrlWithQuery(['page' => $i]) }}">{{ $i }}</a>
    </li>
    @endfor
  </ul>
</nav>
@endif