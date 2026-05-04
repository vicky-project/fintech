<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\Category;

class CategoryController extends Controller
{
  /**
  * Display a listing of active categories.
  */
  public function index(): JsonResponse
  {
    $categories = Category::active()
    ->orderBy('name')
    ->get(['id', 'name', 'icon', 'color', 'type']);

    return response()->json([
      'success' => true,
      'data' => $categories
    ]);
  }
}