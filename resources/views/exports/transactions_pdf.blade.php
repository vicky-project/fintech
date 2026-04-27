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

    /* Warna untuk jumlah */
    .text-income {
      color: #28A745;
      /* hijau */
      font-weight: bold;
    }
    .text-expense {
      color: #DC3545;
      /* merah */
      font-weight: bold;
    }
    .text-neutral {
      color: #000000;
      /* hitam biasa, untuk transfer */
    }
  </style>
</head>
<body>
  <h3>{{ $title }}</h3>
  <table>
    <thead>
      <tr>
        <th>Tanggal</th>
        <th>Tipe</th>
        <th>Kategori</th>
        <th>Dompet</th>
        <th>Jumlah</th>
        <th>Deskripsi</th>
      </tr>
    </thead>
    <tbody>
      @if(empty($data))
      <tr><td colspan="6" style="text-align:center;">Tidak ada data</td></tr>
      @else
      @foreach($data as $row)
      @php
      $type = $row['Tipe'] ?? '';
      $amountClass = match($type) {
      'Pemasukan' => 'text-income',
      'Pengeluaran' => 'text-expense',
      default => 'text-neutral'
      };
      @endphp
      <tr>
        <td>{{ $row['Tanggal'] ?? '' }}</td>
        <td>{{ $type }}</td>
        <td>{{ $row['Kategori'] ?? '' }}</td>
        <td>{{ $row['Dompet'] ?? '' }}</td>
        <td class="{{ $amountClass }}">{{ $row['Jumlah'] ?? '' }}</td>
        <td>{{ $row['Deskripsi'] ?? '' }}</td>
      </tr>
      @endforeach
      @endif
    </tbody>
  </table>

  <!-- Subtotal -->
  @if(isset($summary))
  <div style="margin-top: 15px; text-align: right; font-size: 12px;">
    <strong>Pemasukan: <span class="text-income">{{ $summary['symbol'].' ' . number_format($summary['total_income'], 0, ',', '.') }}</span></strong><br>
    <strong>Pengeluaran: <span class="text-expense">{{ $summary['symbol'] .' ' . number_format($summary['total_expense'], 0, ',', '.') }}</span></strong><br>
    <strong>Net: {{ $summary['symbol'].' ' . number_format($summary['net'], 0, ',', '.') }}</strong>
  </div>
  @endif
</body>
</html>