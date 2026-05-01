<?php

namespace Modules\FinTech\Exports;

use Modules\FinTech\Exports\ChartDataProcessor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfDataExport
{
  public static function generate(string $type, array $data, array $summary): string
  {
    set_time_limit(60);
    ini_set('memory_limit', '256M');

    $extra = [];
    $chartBase64 = $trendChartBase64 = '';

    if ($type === 'transactions') {
      if (($summary['include_chart'] ?? false) && !empty($data)) {
        $chartFile = ChartDataProcessor::createBarChart($data);
        $trendFile = ChartDataProcessor::createTrendChart($data);

        $chartBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($chartFile));
        $trendChartBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($trendFile));

        @unlink($chartFile);
        @unlink($trendFile);
      }

      if ($summary['include_monthly_summary'] ?? false) {
        $extra['monthlySummary'] = self::buildMonthlySummary($data);
        $extra['stats'] = self::buildStats($data);
      }
      if ($summary['include_top5'] ?? false) {
        $extra['topSpending'] = self::buildTopSpending($data);
        $extra['topIncome'] = self::buildTopIncome($data);
      }
    }

    $html = view("fintech::exports.{$type}_pdf", [
      'data' => $data,
      'title' => self::getTitle($type),
      'summary' => $summary,
      'extra' => $extra,
      'chartBase64' => $chartBase64,
      'trendChartBase64' => $trendChartBase64,
    ])->render();

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $filename = Str::uuid()->toString() . '.pdf';
    $tempPath = "temp/exports/{$filename}";
    Storage::disk('local')->put($tempPath, $dompdf->output());

    return Storage::disk('local')->path($tempPath);
  }

  private static function buildMonthlySummary(array $transactions): array
  {
    $grouped = [];
    foreach ($transactions as $row) {
      $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
      if (!$date) continue;
      $key = $date->format('Y-m');
      if (!isset($grouped[$key])) {
        $grouped[$key] = ['income' => 0,
          'expense' => 0,
          'label' => $date->format('M Y')];
      }
      $grouped[$key]['income'] += ChartDataProcessor::parseCurrency($row['Pemasukan'] ?? '0');
      $grouped[$key]['expense'] += ChartDataProcessor::parseCurrency($row['Pengeluaran'] ?? '0');
    }
    ksort($grouped);
    return $grouped;
  }

  private static function buildTopSpending(array $transactions): array
  {
    $expenses = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pengeluaran');
    usort($expenses, function ($a, $b) {
      $aVal = ChartDataProcessor::parseCurrency($a['Pengeluaran'] ?? '0');
      $bVal = ChartDataProcessor::parseCurrency($b['Pengeluaran'] ?? '0');
      return $bVal <=> $aVal;
    });
    return array_slice($expenses, 0, 5);
  }

  private static function buildTopIncome(array $transactions): array
  {
    $incomes = array_filter($transactions, fn($r) => ($r['Tipe'] ?? '') === 'Pemasukan');
    usort($incomes, fn($a, $b) =>
      (float)($b['Pemasukan'] ?? 0) <=> (float)($a['Pemasukan'] ?? 0)
    );
    return array_slice($incomes, 0, 5);
  }

  private static function getTitle(string $type): string
  {
    return match ($type) {
      'transactions' => 'Laporan Transaksi',
      'transfers' => 'Laporan Transfer',
      'budgets' => 'Laporan Budget',
      default => 'Data'
      };
    }

    private static function buildStats(array $transactions): array
    {
      $grouped = [];
      foreach ($transactions as $row) {
        $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
        if (!$date) continue;
        $key = $date->format('Y-m');
        if (!isset($grouped[$key])) {
          $grouped[$key] = ['income' => 0,
            'expense' => 0];
        }
        $grouped[$key]['income'] += (float)($row['Pemasukan'] ?? 0);
        $grouped[$key]['expense'] += (float)($row['Pengeluaran'] ?? 0);
      }
      $totalIncome = array_sum(array_column($grouped, 'income'));
      $totalExpense = array_sum(array_column($grouped, 'expense'));
      $monthCount = count($grouped);
      return [
        'avgIncome' => $monthCount > 0 ? $totalIncome / $monthCount : 0,
        'avgExpense' => $monthCount > 0 ? $totalExpense / $monthCount : 0,
        'ratio' => $totalIncome > 0 ? ($totalExpense / $totalIncome) * 100 : 0,
      ];
    }
  }