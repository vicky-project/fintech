@php
$symbol = $summary['symbol'] ?? 'Rp';
$prec   = $summary['precision'] ?? 0;
$dec    = $summary['decimal_mark'] ?? ',';
$thou   = $summary['thousands_separator'] ?? '.';
$fmt    = fn($v) => $symbol . ' ' . number_format($v, $prec, $dec, $thou);
@endphp
<table>
  <thead>
    <tr><th>Kategori</th><th>Dompet</th><th>Periode</th><th>Limit</th><th>Pengeluaran</th><th>Persentase</th><th>Status</th></tr>
  </thead>
  <tbody>
    @forelse($data as $row)
    <tr>
      <td>{{ $row['Kategori'] }}</td>
      <td>{{ $row['Dompet'] }}</td>
      <td>{{ $row['Periode'] }}</td>
      <td class="text-right">{{ $row['Limit'] === '-' ? '0' : $row['Limit'] }}</td>
      <td class="text-right">{{ $row['Pengeluaran'] === '-' ? '0' : $row['Pengeluaran'] }}</td>
      <td class="text-right">{{ $row['Persentase'] }}</td>
      <td class="@if($row['Status']==='Terlampaui')status-overspent@elseif($row['Status']==='Mendekati')status-near-limit@else status-on-track @endif">{{ $row['Status'] }}</td>
    </tr>
    @empty
    <tr>
      <td colspan="7" style="text-align: center;">Tidak ada data</td>
    </tr>
    @endforelse
    @if(!empty($data))
    <tr class="sub-row">
      <td colspan="3">SUBTOTAL</td>
      <td class="text-right">{{ $fmt($summary['total_limit']) }}</td>
      <td class="text-right">{{ $fmt($summary['total_spent']) }}</td>
      <td></td>
      <td class="text-right">Sisa: {{ $fmt($summary['remaining']) }}</td>
    </tr>
    @endif
  </tbody>
</table>