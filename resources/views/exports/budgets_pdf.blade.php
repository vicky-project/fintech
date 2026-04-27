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
      <tr><th>Kategori</th><th>Dompet</th><th>Periode</th><th>Limit</th><th>Pengeluaran</th><th>Persentase</th><th>Status</th></tr>
    </thead>
    <tbody>
      @foreach($data as $row)
      <tr>
        <td>{{ $row['Kategori'] }}</td>
        <td>{{ $row['Dompet'] }}</td>
        <td>{{ $row['Periode'] }}</td>
        <td class="text-right">{{ $row['Limit'] === '-' ? '0' : $row['Limit'] }}</td>
        <td class="text-right">{{ $row['Pengeluaran'] === '-' ? '0' : $row['Pengeluaran'] }}</td>
        <td class="text-right">{{ $row['Persentase'] }}</td>
        <td class="@if($row['Status']==='Terlampaui')status-overspent@elseif($row['Status']==='Mendekati')status-near-limit@else status-on-track @endif">{{ $row['Status'] }}</td>
      </tr>
      @endforeach
      <tr class="sub-row">
        <td colspan="3" style="text-align:right;">SUBTOTAL</td>
        <td class="text-right">@php echo $summary['symbol'].' '.number_format($summary['total_limit'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
        <td class="text-right">@php echo $summary['symbol'].' '.number_format($summary['total_spent'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
        <td></td>
        <td class="text-right">Sisa: @php echo $summary['symbol'].' '.number_format($summary['remaining'], $summary['precision'], $summary['decimal_mark'], $summary['thousands_separator']) @endphp</td>
      </tr>
    </tbody>
  </table>
</body>
</html>