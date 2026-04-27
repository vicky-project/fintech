<!DOCTYPE html>
<html>
<head>
  <title>{{ $title }}</title>
  <meta charset="utf-8">
  <style>
    body {
      font-family: DejaVu Sans, sans-serif;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 10px;
    }
    th, td {
      border: 1px solid #ddd;
      padding: 6px 8px;
      font-size: 12px;
    }
    th {
      background-color: #f2f2f2;
      text-align: left;
    }
    h3 {
      margin-bottom: 5px;
    }
    .text-right {
      text-align: right;
    }
    .text-center {
      text-align: center;
    }
    .status-overspent {
      color: #dc3545;
      font-weight: bold;
    }
    .status-near-limit {
      color: #ffc107;
    }
    .status-on-track {
      color: #198754;
    }
  </style>
</head>
<body>
  <h3>{{ $title }}</h3>
  <table>
    <thead>
      <tr>
        <th>Kategori</th>
        <th>Dompet</th>
        <th>Periode</th>
        <th class="text-right">Limit</th>
        <th class="text-right">Pengeluaran</th>
        <th class="text-center">Persentase</th>
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
</body>
</html>