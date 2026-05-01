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
      <th rowspan="2">Tanggal</th>
      <th rowspan="2">Tipe</th>
      <th rowspan="2">Kategori</th>
      <th rowspan="2">Dompet</th>
      <th colspan="2">Amount</th>
      @if($showDesc)
      <th rowspan="2">Deskripsi</th>
      @endif
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
      @if($showDesc)
      <td>{{ $row['Deskripsi'] }}</td>
      @endif
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
    @php
    $totalIncome = 0;
    $totalExpense = 0;
    @endphp
    @foreach($extra['monthlySummary'] as $item)
    @php
    $net = $item['income'] - $item['expense'];
    $totalIncome += $item['income'];
    $totalExpense += $item['expense'];
    @endphp
    <tr>
      <td>{{ $item['label'] }}</td>
      <td class="text-right text-income">{{ $fmt($item['income']) }}</td>
      <td class="text-right text-expense">{{ $fmt($item['expense']) }}</td>
      <td class="text-right {{ $net >= 0 ? 'text-income' : 'text-expense' }}">{{ $fmt($net) }}</td>
    </tr>
    @endforeach
    <tr class="sub-row">
      <td><strong>Total</strong></td>
      <td class="text-right">{{ $fmt($totalIncome) }}</td>
      <td class="text-right">{{ $fmt($totalExpense) }}</td>
      <td class="text-right">{{ $fmt($totalIncome - $totalExpense) }}</td>
    </tr>
  </tbody>
</table>

@if(!empty($extra['stats']))
<div style="margin-top: 5px;margin-bottom: 20px;">
  <table>
    <tbody>
      <tr>
        <td>
          <strong>
            Rata Rata Pemasukan/Bulan
          </strong>
        </td>
        <td class="text-right">
          {{ $fmt($extra['stats']['avgIncome']) }}
        </td>
      </tr>
      <tr>
        <td>
          <strong>
            Rata Rata Pengeluaran/Bulan
          </strong>
        </td>
        <td class="text-right">
          {{ $fmt($extra['stats']['avgExpense']) }}
        </td>
      </tr>
      <tr>
        <td>
          <strong>
            Rasio Pengeluaran
          </strong>
        </td>
        <td class="text-right">
          {{ round($extra['stats']['ratio'], 1) }}%
        </td>
      </tr>
    </tbody>
  </table>
</div>
@endif
@endif

@if(!empty($extra['topSpending']))
<h4>Top 5 Pengeluaran</h4>
<table>
  <thead>
    <tr>
      <th>Tanggal</th>
      <th>Kategori</th>
      <th>Jumlah</th>
      @if($showDesc)
      <th>Deskripsi</th>
      @endif
    </tr>
  </thead>
  <tbody>
    @foreach($extra['topSpending'] as $item)
    @php
    $amount = (float) str_replace(['Rp', '.', ','], '', $item['Pengeluaran'] ?? '0');
    @endphp
    <tr>
      <td>{{ $item['Tanggal'] }}</td>
      <td>{{ $item['Kategori'] }}</td>
      <td class="text-right text-expense">{{ $fmt($amount) }}</td>
      @if($showDesc)
      <td>{{ $item['Deskripsi'] ?? '-' }}</td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
@endif

@if(!empty($extra['topIncome']))
<h4>Top 5 Pemasukan</h4>
<table>
  <thead>
    <tr>
      <th>Tanggal</th><th>Kategori</th><th>Jumlah</th>
      @if($showDesc)
      <th>Deskripsi</th>
      @endif
    </tr>
  </thead>
  <tbody>
    @foreach($extra['topIncome'] as $item)
    <tr>
      <td>{{ $item['Tanggal'] }}</td>
      <td>{{ $item['Kategori'] }}</td>
      <td class="text-right text-income">{{ $fmt((float) str_replace(['Rp','.',','], '', $item['Pemasukan'])) }}</td>
      @if($showDesc)
      <td>{{ $item['Deskripsi'] ?? '-' }}</td>
      @endif
    </tr>
    @endforeach
  </tbody>
</table>
@endif

@if(!empty($extra['categoryExpense']))
<h4>Persentase Kategori Pengeluaran</h4>
<table>
  <thead>
    <tr>
      <th>Kategori</th>
      <th>Total</th>
      <th>Persentase</th>
      <th>Rata‑rata</th>
    </tr>
  </thead>
  <tbody>
    @php
    $symbol = $summary['symbol'] ?? 'Rp';
    $precision = $summary['precision'] ?? 0;
    $decimal = $summary['decimal_mark'] ?? ',';
    $thousands = $summary['thousands_separator'] ?? '.';
    $fmt = function($val) use ($symbol, $precision, $decimal, $thousands) {
    return $symbol . ' ' . number_format((float)$val, $precision, $decimal, $thousands);
    };
    @endphp
    @foreach($extra['categoryExpense'] as $item)
    <tr>
      <td>{{ $item['cat'] }}</td>
      <td class="text-right text-expense">{{ $fmt($item['total']) }}</td>
      <td class="text-right">{{ round($item['percentage'], 1) }}%</td>
      <td class="text-right text-expense">{{ $fmt($item['average']) }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif

@if(!empty($chartBase64))
<div style="text-align: center; margin-bottom: 20px; margin-top: 20px;">
  <img src="{{ $chartBase64 }}" alt="Chart Pemasukan vs Pengeluaran" style="max-width: 100%; height: auto;">
</div>
@endif

@if(!empty($trendChartBase64))
<div style="text-align: center; margin: 20px 0;">
  <img src="{{ $trendChartBase64 }}" alt="Chart Tren Net" style="max-width: 100%; height: auto;">
</div>
@endif

@if(!empty($pieChartBase64))
<div style="text-align: center; margin: 20px 0;">
  <img src="{{ $pieChartBase64 }}" alt="Pie Chart Kategori" style="max-width: 100%; height: auto;">
</div>
@endif