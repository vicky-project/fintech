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
        <th>Tanggal</th>
        <th>Dari</th>
        <th>Ke</th>
        <th>Jumlah</th>
        <th>Deskripsi</th>
      </tr>
    </thead>
    <tbody>
      @if(empty($data))
      <tr><td colspan="5" style="text-align:center;">Tidak ada data</td></tr>
      @else
      @foreach($data as $row)
      <tr>
        <td>{{ $row['Tanggal'] ?? '' }}</td>
        <td>{{ $row['Dari'] ?? '' }}</td>
        <td>{{ $row['Ke'] ?? '' }}</td>
        <td class="text-right">{{ $row['Jumlah'] ?? '' }}</td>
        <td>{{ $row['Deskripsi'] ?? '-' }}</td>
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
    $total = $summary['total'] ?? 0;
    @endphp
    <strong>Total Transfer: {{ $symbol }} {{ number_format($total, $precision, $decimalMark, $thousandsSep) }}</strong>
  </div>
  @endif
</body>
</html>