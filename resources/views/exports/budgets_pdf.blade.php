<!DOCTYPE html>
<html>
<head>
  <title>{{ $title }}</title>
  <meta charset="utf-8">
  <style>
    body {
      font-family: DejaVu Sans, sans-serif;
    }
    .header-info {
      margin-bottom: 15px;
    }
    .header-info p {
      margin: 0;
      font-size: 11px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    th, td {
      border: 1px solid #ddd;
      padding: 6px 8px;
      font-size: 11px;
    }
    th {
      background-color: #4F81BD;
      color: #ffffff;
      font-weight: bold;
      text-align: center;
    }
    td {
      text-align: left;
    }
    .text-right {
      text-align: right;
    }
    .subtotal {
      margin-top: 15px;
      text-align: right;
      font-size: 12px;
    }
    .status-overspent {
      color: #DC3545;
      font-weight: bold;
    }
    .status-near-limit {
      color: #FFC107;
    }
    .status-on-track {
      color: #198754;
    }
  </style>
</head>
<body>
  <h3>{{ $title }}</h3>

  @if(isset($summary['metadata']))
  <div class="header-info">
    @foreach($summary['metadata'] as $info)
    <p>
      {{ $info }}
    </p>
    @endforeach
  </div>
  @endif

  <table>
    <thead>
      <tr>
        <th>Kategori</th>
        <th>Dompet</th>
        <th>Periode</th>
        <th>Limit</th>
        <th>Pengeluaran</th>
        <th>Persentase</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      @if(empty($data))
      <tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>
      @else
      @foreach($data as $row)
      <tr>
        <td>{{ $row['Kategori'] ?? '' }}</td>
        <td>{{ $row['Dompet'] ?? '' }}</td>
        <td>{{ $row['Periode'] ?? '' }}</td>
        <td class="text-right">{{ $row['Limit'] ?? '' }}</td>
        <td class="text-right">{{ $row['Pengeluaran'] ?? '' }}</td>
        <td class="text-center">{{ $row['Persentase'] ?? '' }}</td>
        <td class="
          @if(isset($row['Status']))
          @if($row['Status'] === 'Terlampaui') status-overspent
          @elseif($row['Status'] === 'Mendekati') status-near-limit
          @else status-on-track
          @endif
          @endif
          ">{{ $row['Status'] ?? '' }}</td>
      </tr>
      @endforeach
      @endif
    </tbody>
  </table>

  @if(isset($summary))
  <div class="subtotal">
    @php
    $symbol = $summary['symbol'] ?? 'Rp';
    $precision = $summary['precision'] ?? 0;
    $decimalMark = $summary['decimal_mark'] ?? ',';
    $thousandsSep = $summary['thousands_separator'] ?? '.';
    $format = function($val) use ($symbol, $precision, $decimalMark, $thousandsSep) {
    return $symbol . ' ' . number_format($val, $precision, $decimalMark, $thousandsSep);
    };
    @endphp
    <strong>Total Limit: {{ $format($summary['total_limit']) }}</strong>
    <strong>Total Pengeluaran: {{ $format($summary['total_spent']) }}</strong>
    <strong>Sisa: {{ $format($summary['remaining']) }}</strong>
  </div>
  @endif
</body>
</html>