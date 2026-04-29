@php
$symbol = $summary['symbol'] ?? 'Rp';
$prec   = $summary['precision'] ?? 0;
$dec    = $summary['decimal_mark'] ?? ',';
$thou   = $summary['thousands_separator'] ?? '.';
$fmt    = fn($v) => $symbol . ' ' . number_format($v, $prec, $dec, $thou);
@endphp
<table>
  <thead>
    <tr>
      <th rowspan="2">Tanggal</th>
      <th rowspan="2">Tipe</th>
      <th rowspan="2">Kategori</th>
      <th rowspan="2">Dompet</th>
      <th colspan="2">Amount</th>
      <th rowspan="2">Deskripsi</th>
    </tr>
    <tr>
      <th>Pemasukan</th>
      <th>Pengeluaran</th>
    </tr>
  </thead>
  <tbody>
    @forelse($data as $row)
    <tr>
      <td>{{ $row['Tanggal'] }}</td>
      <td>{{ $row['Tipe'] }}</td>
      <td>{{ $row['Kategori'] }}</td>
      <td>{{ $row['Dompet'] }}</td>
      <td class="text-right text-income">{{ $row['Pemasukan'] === '-' ? '0' : $row['Pemasukan'] }}</td>
      <td class="text-right text-expense">{{ $row['Pengeluaran'] === '-' ? '0' : $row['Pengeluaran'] }}</td>
      <td>{{ $row['Deskripsi'] }}</td>
    </tr>
    @empty
    <tr>
      <td colspan="7" style="text-align: center;">Tidak ada data</td>
    </tr>
    @endforelse
    @if(!empty($data))
    <tr class="sub-row">
      <td colspan="4">SUBTOTAL</td>
      <td class="text-right">{{ $fmt($summary['total_income']) }}</td>
      <td class="text-right">{{ $fmt($summary['total_expense']) }}</td>
      <td class="text-right">Net: {{ $fmt($summary['net']) }}</td>
    </tr>
    @endif
  </tbody>
</table>
@if(!empty($extra['monthlySummary']))
<h4>Ringkasan Bulanan</h4>
<table>
  <thead>
    <tr>
      <th>Bulan</th>
      <th>Pemasukan</th>
      <th>Pengeluaran</th>
      <th>Net</th>
    </tr>
  </thead>
  <tbody>
    @foreach($extra['monthlySummary'] as $date => $item)
    <tr>
      <td>{{ $item['label'] }}</td>
      <td class="text-right text-income">{{ $fmt($item['income']) }}</td>
      <td class="text-right text-expense">{{ $fmt($item['expense']) }}</td>
      @php $net = $item['income'] - $item['expense']; @endphp
      <td class="text-right {{ $net >= 0 ? 'text-income' : 'text-expense' }}">{{ $fmt($net) }}</td>
    </tr>
    @endforeach
    <tr class="sub-row">
      <td><strong>Total</strong></td>
      <td class="text-right"><strong>{{ $fmt(array_sum(array_column($extra['monthlySummary'], 'income'))) }}</strong></td>
      <td class="text-right"><strong>{{ $fmt(array_sum(array_column($extra['monthlySummary'], 'expense'))) }}</strong></td>
      @php $totalNet = array_sum(array_column($extra['monthlySummary'], 'income')) - array_sum(array_column($extra['monthlySummary'], 'expense')); @endphp
      <td class="text-right"><strong>{{ $fmt($totalNet) }}</strong></td>
    </tr>
  </tbody>
</table>
@endif

@if(!empty($extra['topSpending']))
<h4>Top 5 Pengeluaran</h4>
<table>
  <thead>
    <tr>
      <th>Tanggal</th>
      <th>Kategori</th>
      <th>Jumlah</th>
      <th>Deskripsi</th>
    </tr>
  </thead>
  <tbody>
    @foreach($extra['topSpending'] as $item)
    <tr>
      <td>{{ $item['Tanggal'] }}</td>
      <td>{{ $item['Kategori'] }}</td>
      <td class="text-right text-expense">{{ $fmt($item['Pengeluaran']) }}</td>
      <td>{{ $item['Deskripsi'] ?? '-' }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif