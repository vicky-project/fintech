@php
$symbol = $summary['symbol'] ?? 'Rp';
$prec   = $summary['precision'] ?? 0;
$dec    = $summary['decimal_mark'] ?? ',';
$thou   = $summary['thousands_separator'] ?? '.';
$fmt    = fn($v) => $symbol . ' ' . number_format($v, $prec, $dec, $thou);
@endphp
<table>
  <thead>
    <tr><th>Tanggal</th><th>Dari</th><th>Ke</th><th>Jumlah</th><th>Deskripsi</th></tr>
  </thead>
  <tbody>
    @foreach($data as $row)
    <tr>
      <td>{{ $row['Tanggal'] }}</td>
      <td>{{ $row['Dari'] }}</td>
      <td>{{ $row['Ke'] }}</td>
      <td class="text-right">{{ $row['Jumlah'] === '-' ? '0' : $row['Jumlah'] }}</td>
      <td>{{ $row['Deskripsi'] }}</td>
    </tr>
    @endforeach
    <tr class="sub-row">
      <td colspan="3">SUBTOTAL</td>
      <td class="text-right">{{ $fmt($summary['total']) }}</td>
      <td></td>
    </tr>
  </tbody>
</table>