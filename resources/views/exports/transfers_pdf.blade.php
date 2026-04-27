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
  </style>
</head>
<body>
  <h3>{{ $title }}</h3>
  <!-- di bawah <h3> -->
  <div style="margin-bottom: 10px; font-size: 12px;">
    @isset($summary['metadata'])
    @foreach($summary['metadata'] as $info)
    <p style="margin: 0;">
      {{ $info }}
    </p>
    @endforeach
    @endisset
  </div>
  <!-- kemudian tabel seperti biasa -->
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
        <td>{{ $row['Jumlah'] ?? '' }}</td>
        <td>{{ $row['Deskripsi'] ?? '' }}</td>
      </tr>
      @endforeach
      @endif
    </tbody>
  </table>
  @if(isset($summary))
  <div style="margin-top: 15px; text-align: right; font-size: 12px;">
    <strong>Total Transfer: {{ 'Rp ' . number_format($summary['total'], 0, ',', '.') }}</strong>
  </div>
  @endif
</body>
</html>