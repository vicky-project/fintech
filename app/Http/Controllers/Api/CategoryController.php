<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Models\Category;
use Modules\FinTech\Services\CategorizationService;

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

  /**
  * Sarankan kategori berdasarkan deskripsi.
  */
  public function suggest(Request $request, CategorizationService $service): JsonResponse
  {
    $request->validate([
      'description' => 'required|string|min:3',
      'type' => 'required|in:income,expense',
    ]);

    $category = $service->suggest(
      $request->input('description'),
      $request->input('type')
    );

    return response()->json([
      'success' => true,
      'data' => $category ? [
        'id' => $category->id,
        'name' => $category->name,
        'icon' => $category->icon,
        'color' => $category->color,
      ] : null,
    ]);
  }
}