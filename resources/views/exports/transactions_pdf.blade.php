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
  <table>
    <thead>
      <tr>
        <th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Dompet</th><th>Jumlah</th><th>Deskripsi</th>
      </tr>
    </thead>
    <tbody>
      @if(empty($data))
      <tr><td colspan="6" style="text-align:center;">Tidak ada data</td></tr>
      @else
      @foreach($data as $row)
      <tr>
        <td>{{ $row['Tanggal'] ?? '' }}</td>
        <td>{{ $row['Tipe'] ?? '' }}</td>
        <td>{{ $row['Kategori'] ?? '' }}</td>
        <td>{{ $row['Dompet'] ?? '' }}</td>
        <td>{{ $row['Jumlah'] ?? '' }}</td>
        <td>{{ $row['Deskripsi'] ?? '' }}</td>
      </tr>
      @endforeach
      @endif
    </tbody>
  </table>
</body>
</html>