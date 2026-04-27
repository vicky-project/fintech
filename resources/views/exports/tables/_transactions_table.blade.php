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
    @foreach($data as $row)
    <tr>
      <td>{{ $row['Tanggal'] }}</td>
      <td>{{ $row['Tipe'] }}</td>
      <td>{{ $row['Kategori'] }}</td>
      <td>{{ $row['Dompet'] }}</td>
      <td class="text-right text-income">{{ $row['Pemasukan'] === '-' ? '0' : $row['Pemasukan'] }}</td>
      <td class="text-right text-expense">{{ $row['Pengeluaran'] === '-' ? '0' : $row['Pengeluaran'] }}</td>
      <td>{{ $row['Deskripsi'] }}</td>
    </tr>
    @endforeach
    <tr class="sub-row">
      <td colspan="4">SUBTOTAL</td>
      <td class="text-right">{{ $fmt($summary['total_income']) }}</td>
      <td class="text-right">{{ $fmt($summary['total_expense']) }}</td>
      <td class="text-right">Net: {{ $fmt($summary['net']) }}</td>
    </tr>
  </tbody>
</table>