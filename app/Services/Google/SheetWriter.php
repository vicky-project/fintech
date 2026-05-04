<?php

namespace Modules\FinTech\Services\Google;

use Modules\FinTech\Services\Google\Writers;

class SheetWriter
{
  protected string $spreadsheetId;
  protected string $sheetName;
  protected ?int $sheetId = null;

  public function __construct(
    protected GoogleSheetsClient $client,
    protected SpreadsheetManager $manager,
    protected Writers\ValueWriter $valueWriter,
    protected Writers\StyleBuilder $styleBuilder,
    protected Writers\TitleWriter $titleWriter,
    protected Writers\HeaderWriter $headerWriter,
    protected Writers\DataWriter $dataWriter,
    protected Writers\MetadataWriter $metadataWriter,
    protected Writers\CurrencyFormatter $currencyFormatter,
    protected Writers\ColorApplier $colorApplier,
    protected Writers\BorderApplier $borderApplier,
    protected Writers\FilterApplier $filterApplier,
    protected Writers\ChartWriter $chartWriter,
    protected Writers\SummaryWriter $summaryWriter,
    protected Writers\FooterWriter $footerWriter,
    protected Writers\SheetResizer $sheetResizer,
    protected Writers\ClearWriter $clearWriter
  ) {}

  public function beginBatch(string $spreadsheetId, string $sheetName): void
  {
    $this->spreadsheetId = $spreadsheetId;
    $this->sheetName = $sheetName;
    $this->sheetId = $this->manager->getSheetIdByName($spreadsheetId, $sheetName);
    $this->valueWriter->reset();
    $this->styleBuilder->reset();
  }

  public function commitValues(): void
  {
    $this->valueWriter->commit($this->client, $this->spreadsheetId);
  }

  public function commit(): void
  {
    $this->styleBuilder->commit($this->client, $this->spreadsheetId);
  }

  // ─── Delegasi ke writer spesifik ───

  public function writeTitle(string $title, SheetCursor $cursor, int $colCount): void
  {
    $this->titleWriter->writeTitle($this->sheetName, $title, $cursor, $colCount, $this->sheetId);
  }

  public function writeSimpleTitle(string $title, SheetCursor $cursor, int $colCount = 4): void
  {
    $this->titleWriter->writeSimpleTitle($this->sheetName, $title, $cursor, $colCount, $this->sheetId);
  }

  public function writeSimpleHeader(array $headers, SheetCursor $cursor): void
  {
    $this->headerWriter->writeSimpleHeader($this->sheetName, $headers, $cursor, $this->sheetId);
  }

  public function writeData(array $values, SheetCursor $cursor): int
  {
    return $this->dataWriter->writeData($this->sheetName, $values, $cursor);
  }

  public function writeMetadata(array $metadata, SheetCursor $cursor, int $colCount = 7): void
  {
    $this->metadataWriter->writeMetadata($this->sheetName, $metadata, $cursor, $colCount, $this->sheetId);
  }

  public function applyCurrencyFormat(int $dataStartRow, int $dataEndRow, array $summary, int $startCol = 4, int $colCount = 2): void
  {
    $this->currencyFormatter->applyCurrencyFormat($this->sheetId, $dataStartRow, $dataEndRow, $summary, $startCol, $colCount);
  }

  public function applyTransactionColors(array $values, int $dataStartRow, int $dataEndRow): void
  {
    $this->colorApplier->applyTransactionColors($this->sheetId, $values, $dataStartRow, $dataEndRow);
  }

  public function applySummaryColors(int $headerRow, array $values, int $startCol = 0): void
  {
    $this->colorApplier->applySummaryColors($this->sheetId, $headerRow, $values, $startCol);
  }

  public function applyTopSpendingColors(int $headerRow, array $values, int $startCol = 0): void
  {
    $this->colorApplier->applyTopSpendingColors($this->sheetId, $headerRow, $values, $startCol);
  }

  public function applyBordersToRange(int $startRow, int $endRow, int $startCol = 0, int $endCol = 0, array $headers = []): void
  {
    $colCount = $endCol > 0 ? $endCol : (count($headers) > 0 ? count($headers) : 7);
    $this->borderApplier->applyBordersToRange($this->sheetId, $startRow, $endRow, $startCol, $endCol, $colCount);
  }

  public function applyBasicFilter(int $headerStartRow, int $headerEndRow, int $startCol = 0, int $colCount = 7): void
  {
    $this->filterApplier->applyBasicFilter($this->sheetId, $headerStartRow, $headerEndRow, $startCol, $colCount);
  }

  public function addTransactionChartRequest(
    int $dataStartRow, int $dataEndRow, int $chartRow, int $chartCol = 0,
    int $domainCol = 0, int $series1Col = 4, int $series2Col = 5
  ): void {
    $this->chartWriter->addTransactionChartRequest($this->sheetId, $dataStartRow, $dataEndRow, $chartRow, $chartCol, $domainCol, $series1Col, $series2Col);
  }

  public function addCategoryPieChartRequest(
    int $dataStartRow, int $dataEndRow, int $categoryCol,
    int $totalCol, int $chartRow, int $chartCol = 0
  ): void {
    $this->chartWriter->addCategoryPieChartRequest($this->sheetId, $dataStartRow, $dataEndRow, $categoryCol, $totalCol, $chartRow, $chartCol);
  }

  public function writeTransactionChart(
    int $dataStartRow, int $dataEndRow, int $chartRow, int $chartCol = 0,
    int $domainCol = 0, int $series1Col = 4, int $series2Col = 5
  ): void {
    $this->chartWriter->writeTransactionChart($this->spreadsheetId, $this->sheetId, $dataStartRow, $dataEndRow, $chartRow, $chartCol, $domainCol, $series1Col, $series2Col);
  }

  public function writeSummaryWithStats(array $transactions, SheetCursor $cursor, array $summary): array
  {
    return $this->summaryWriter->writeSummaryWithStats($this->sheetName, $transactions, $cursor, $summary, $this->sheetId);
  }

  public function writeTopSpendingToSheet(array $transactions, SheetCursor $cursor, array $summary): void
  {
    $this->summaryWriter->writeTopSpendingToSheet($this->sheetName, $transactions, $cursor, $summary, $this->sheetId);
  }

  public function writeTopIncomeToSheet(array $transactions, SheetCursor $cursor, array $summary): void
  {
    $this->summaryWriter->writeTopIncomeToSheet($this->sheetName, $transactions, $cursor, $summary, $this->sheetId);
  }

  public function writeCategoryExpenseTable(array $transactions, SheetCursor $cursor, array $summary): array
  {
    return $this->summaryWriter->writeCategoryExpenseTable($this->sheetName, $transactions, $cursor, $summary, $this->sheetId);
  }

  public function writeFooter(SheetCursor $cursor, array $headers): void
  {
    $this->footerWriter->writeFooter($this->sheetName, $cursor, $headers, $this->sheetId);
  }

  public function autoResizeColumns(int $columnCount): void
  {
    $this->sheetResizer->autoResizeColumns($this->sheetId, $columnCount);
  }

  public function clearSheetBatch(): void
  {
    $this->clearWriter->clearSheetBatch($this->sheetId);
  }
}