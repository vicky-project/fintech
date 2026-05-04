<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\SearchService;

class SearchController extends Controller
{
  protected SearchService $searchService;

  public function __construct(SearchService $searchService) {
    $this->searchService = $searchService;
  }

  public function global(Request $request): JsonResponse
  {
    $request->validate([
      'q' => 'required|string|min:2|max:100'
    ]);

    $results = $this->searchService->search($request->user()->id, $request->q);

    return response()->json([
      'success' => true,
      'data' => $results,
      'total' => count($results)
    ]);
  }
}