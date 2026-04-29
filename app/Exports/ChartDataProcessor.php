<?php

namespace Modules\FinTech\Exports;

use mitoteam\jpgraph\MtJpGraph;

class ChartDataProcessor
{
  public static function parseCurrency(string $input): float
  {
    return (float) str_replace(['Rp', '.', ','], '', $input);
  }

  public static function formatCurrency(float $value, array $formatRules): string
  {
    $num = number_format($value, $formatRules['precision'], $formatRules['decimal_mark'], $formatRules['thousands_separator']);
    return ($formatRules['symbol_first'] ? $formatRules['symbol'] . ' ' : '') . $num . (!$formatRules['symbol_first'] ? ' ' . $formatRules['symbol'] : '');
  }

  public static function groupChartData(array $transactions): array
  {
    usort($transactions, function ($a, $b) {
      $dateA = \DateTime::createFromFormat('d/m/Y', $a['Tanggal'] ?? '') ?: new \DateTime('1970-01-01');
      $dateB = \DateTime::createFromFormat('d/m/Y', $b['Tanggal'] ?? '') ?: new \DateTime('1970-01-01');
      return $dateA <=> $dateB;
    });

    $firstDate = \DateTime::createFromFormat('d/m/Y', $transactions[0]['Tanggal'] ?? '');
    $lastDate = \DateTime::createFromFormat('d/m/Y', $transactions[count($transactions) - 1]['Tanggal'] ?? '');
    if (!$firstDate || !$lastDate) {
      return [[],
        [],
        [],
        '',
        0];
    }

    $interval = $firstDate->diff($lastDate);
    $totalYears = $interval->y + ($interval->m > 0 ? 1 : 0);
    $labels = $incomes = $expenses = [];

    $grouped = [];
    foreach ($transactions as $row) {
      $date = \DateTime::createFromFormat('d/m/Y', $row['Tanggal'] ?? '');
      if (!$date) continue;
      $key = $totalYears >= 3 ? $date->format('Y') : ($interval->m > 0 || $interval->y > 0 ? $date->format('Y-m') : $date->format('Y-m-d'));
      if (!isset($grouped[$key])) $grouped[$key] = ['income' => 0,
        'expense' => 0,
        'label' => ''];
      $grouped[$key]['income'] += self::parseCurrency($row['Pemasukan'] ?? '0');
      $grouped[$key]['expense'] += self::parseCurrency($row['Pengeluaran'] ?? '0');
      if (empty($grouped[$key]['label'])) {
        $grouped[$key]['label'] = $totalYears >= 3 ? $date->format('Y') : ($interval->m > 0 || $interval->y > 0 ? $date->format('M Y') : $date->format('j M Y'));
      }
    }
    ksort($grouped);
    foreach ($grouped as $item) {
      $labels[] = $item['label'];
      $incomes[] = $item['income'];
      $expenses[] = $item['expense'];
    }

    $dataCount = count($labels);
    $chartWidth = min(2000, max(800, $dataCount * max(18, 50)));
    $xAxisTitle = $totalYears >= 3 ? 'Tahun' : ($interval->m > 0 || $interval->y > 0 ? 'Bulan' : 'Tanggal');

    return [$labels,
      $incomes,
      $expenses,
      $xAxisTitle,
      $chartWidth];
  }

  public static function createBarChart(array $transactions, ?string $savePath = null, &$width = null, &$height = null): string
  {
    MtJpGraph::load(['bar']);
    [$labels,
      $incomes,
      $expenses,
      $xAxisTitle,
      $chartWidth] = self::groupChartData($transactions);
    if (empty($labels)) return '';

    $width = $chartWidth;
    $height = 400;

    $graph = new \Graph($chartWidth, 400);
    $graph->SetScale('textlin');
    $graph->img->SetMargin(80, 20, 20, 60);
    $graph->title->Set('Pemasukan vs Pengeluaran');
    $graph->xaxis->title->Set($xAxisTitle);
    $graph->xaxis->SetTickLabels($labels);
    $graph->xaxis->SetLabelAngle(45);
    $graph->xaxis->SetFont(FF_DEFAULT, FS_NORMAL, 8);
    $graph->yaxis->SetFont(FF_DEFAULT, FS_NORMAL, 8);
    $graph->yaxis->scale->SetAutoMin(0);

    $incomePlot = new \BarPlot($incomes);
    $expensePlot = new \BarPlot($expenses);
    $incomePlot->SetFillColor('#28A745');
    $expensePlot->SetFillColor('#DC3545');
    $incomePlot->SetLegend(null);
    $expensePlot->SetLegend(null);
    $graph->Add(new \GroupBarPlot([$incomePlot, $expensePlot]));

    $path = $savePath ?? tempnam(sys_get_temp_dir(), 'chart_') . '.png';
    $graph->Stroke($path);
    return $path;
  }

  public static function createTrendChart(array $transactions, ?string $savePath = null, &$width = null, &$height = null): string
  {
    MtJpGraph::load(['line']);
    [$labels,
      $incomes,
      $expenses,
      $xAxisTitle,
      $chartWidth] = self::groupChartData($transactions);
    if (empty($labels)) return '';

    $width = $chartWidth;
    $height = 300;

    $nets = array_map(fn($i, $e) => $i - $e, $incomes, $expenses);

    $graph = new \Graph($chartWidth, 300);
    $graph->SetScale('textlin');
    $graph->img->SetMargin(60, 20, 20, 60);
    $graph->title->Set('Tren Net (Pemasukan - Pengeluaran)');
    $graph->xaxis->title->Set($xAxisTitle);
    $graph->xaxis->SetTickLabels($labels);
    $graph->xaxis->SetLabelAngle(45);
    $graph->xaxis->SetFont(FF_DEFAULT, FS_NORMAL, 8);
    $graph->yaxis->SetFont(FF_DEFAULT, FS_NORMAL, 8);
    $graph->xaxis->SetPos('min');

    $linePlot = new \LinePlot($nets);
    $linePlot->SetColor('#3366CC');
    $linePlot->SetLegend(null);
    $linePlot->SetFillColor('#CCE5FF');
    $graph->Add($linePlot);

    $path = $savePath ?? tempnam(sys_get_temp_dir(), 'chart_trend_') . '.png';
    $graph->Stroke($path);
    return $path;
  }
}