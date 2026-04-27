<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Modules\FinTech\Http\Requests\ExportRequest;
use Modules\FinTech\Services\ExportService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
  protected ExportService $exportService;

  public function __construct(ExportService $exportService) {
    $this->exportService = $exportService;
  }

  /**
  * Handle export request.
  */
  public function export(ExportRequest $request): BinaryFileResponse|JsonResponse
  {
    try {
      $path = $this->exportService->generate($request->validated());

      return response()
      ->download($path, $this->getFilename($request->type, $request->format))
      ->deleteFileAfterSend(true);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage(),
      ], 422);
    }
  }

  protected function getFilename(string $type, string $format): string
  {
    $timestamp = now()->format('YmdHis');
    return "export_{$type}_{$timestamp}.{$format}";
  }
}