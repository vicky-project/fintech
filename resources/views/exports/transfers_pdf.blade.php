<!DOCTYPE html>
<html>
<head>
  <title>{{ $title }}</title>
  <style>
    body {
      font-family: DejaVu Sans, sans-serif;
    }
    .header-info {
      margin-bottom: 10px;
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
      padding: 5px 6px;
      font-size: 10px;
    }
    th {
      background: #4F81BD;
      color: #fff;
      font-weight: bold;
      text-align: center;
      font-size: 12px;
    }
    .text-right {
      text-align: right;
    }
    .sub-row td {
      font-weight: bold;
      background: #D9E2F3;
      font-size: 11px;
    }
  </style>
</head>
<body>
  <h3>{{ $title }}</h3>
  @if(!empty($summary['metadata']))
  <div class="header-info">
    @foreach($summary['metadata'] as $info)<p>
      {{ $info }}
    </p>
    @endforeach
  </div>
  @endif
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
        <td colspan="3" style="text-align:right;">SUBTOTAL</td>
        <td class="text-right">@php echo $summary['symbol'].' '.number_format($summary['total'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
        <td></td>
      </tr>
    </tbody>
  </table>
</body>
</html>