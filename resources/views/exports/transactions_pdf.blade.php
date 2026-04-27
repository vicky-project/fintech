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
      background-color: #4F81BD;
      color: #fff;
      font-weight: bold;
      text-align: center;
      vertical-align: middle;
      font-size: 12px;
    }
    td {
      vertical-align: top;
    }
    .text-right {
      text-align: right;
    }
    .text-income {
      color: #28A745;
      font-weight: bold;
    }
    .text-expense {
      color: #DC3545;
      font-weight: bold;
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
      <!-- Subtotal sebagai footer tabel -->
      <tr class="sub-row">
        <td colspan="4" style="text-align:right;">SUBTOTAL</td>
        <td class="text-right">@php echo $summary['symbol'].' '.number_format($summary['total_income'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
        <td class="text-right">@php echo $summary['symbol'].' '.number_format($summary['total_expense'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
        <td class="text-right">Net: @php echo $summary['symbol'].' '.number_format($summary['net'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
      </tr>
    </tbody>
  </table>
</body>
</html>