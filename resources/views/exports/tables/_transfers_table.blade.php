@php
$symbol = $summary['symbol'] ?? 'Rp';
$prec   = $summary['precision'] ?? 0;
$dec    = $summary['decimal_mark'] ?? ',';
$thou   = $summary['thousands_separator'] ?? '.';
$fmt    = fn($v) => $symbol . ' ' . number_format((float)$v, $prec, $dec, $thou);
$showDesc = $summary['include_description'] ?? true;
@endphp
<table>
  <thead>
    <tr>
      <th>Tanggal</th><th>Dari</th><th>Ke</th><th>Jumlah</th>
      @if($showDesc)
      <th>Deskripsi</th>
      @endif
    </tr>
  </thead>
  <tbody>
    @forelse($data as $row)
    <tr>
      <td>{{ $row['Tanggal'] }}</td>
      <td>{{ $row['Dari'] }}</td>
      <td>{{ $row['Ke'] }}</td>
      <td class="text-right">{{ $row['Jumlah'] === '-' ? '0' : $row['Jumlah'] }}</td>
      @if($showDesc)
      <td>{{ $row['Deskripsi'] }}</td>
      @endif
    </tr>
    @empty
    <tr>
      <td colspan="5" style="text-align: center;">Tidak ada data</td>
    </tr>
    @endforelse
    @if(!empty($data))
    <tr class="sub-row">
      <td colspan="3">SUBTOTAL</td>
      <td class="text-right">{{ $fmt($summary['total']) }}</td>
      <td></td>
    </tr>
    @endif
  </tbody>
</table>