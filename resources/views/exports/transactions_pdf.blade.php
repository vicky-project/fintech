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
    .text-center {
      text-align: center;
    }
    .text-income {
      color: #28A745;
      font-weight: bold;
    }
    .text-expense {
      color: #DC3545;
      font-weight: bold;
    }
    .subtotal {
      margin-top: 15px;
      text-align: right;
      font-size: 12px;
    }
    .subtotal strong {
      display: block;
    }
  </style>
</head>
<body>
  <h3>{{ $title }}</h3>

  <!-- Metadata -->
  @if(isset($summary['metadata']))
  <div class="header-info">
    @foreach($summary['metadata'] as $info)
    <p>
      {{ $info }}
    </p>
    @endforeach
  </div>
  @endif

  <!-- Tabel Transaksi -->
  <table>
    <thead>
      <tr>
        <th>Tanggal</th>
        <th>Tipe</th>
        <th>Kategori</th>
        <th>Dompet</th>
        <th colspan="2" style="text-align: center;">Amount</th>
        <th>Deskripsi</th>
      </tr>
      <tr>
        <th></th>
        <th></th>
        <th></th>
        <th></th>
        <th>Pemasukan</th>
        <th>Pengeluaran</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      @if(empty($data))
      <tr><td colspan="7" style="text-align:center;">Tidak ada data</td></tr>
      @else
      @foreach($data as $row)
      <tr>
        <td>{{ $row['Tanggal'] ?? '' }}</td>
        <td>{{ $row['Tipe'] ?? '' }}</td>
        <td>{{ $row['Kategori'] ?? '' }}</td>
        <td>{{ $row['Dompet'] ?? '' }}</td>
        <td class="text-right text-income">{{ $row['Pemasukan'] ?? '-' }}</td>
        <td class="text-right text-expense">{{ $row['Pengeluaran'] ?? '-' }}</td>
        <td>{{ $row['Deskripsi'] ?? '-' }}</td>
      </tr>
      @endforeach
      @endif
    </tbody>
  </table>

  <!-- Subtotal -->
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
    <strong>Pemasukan: {{ $format($summary['total_income']) }}</strong>
    <strong>Pengeluaran: {{ $format($summary['total_expense']) }}</strong>
    <strong>Net: {{ $format($summary['net']) }}</strong>
  </div>
  @endif
</body>
</html>