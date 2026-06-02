<?php

namespace Modules\FinTech\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinTech\Services\SearchService;
use Modules\FinTech\Traits\ResolvesTelegramUser;

class SearchController extends Controller
{
  use ResolvesTelegramUser;

  protected SearchService $searchService;

  public function __construct(SearchService $searchService) {
    $this->searchService = $searchService;
  }

  public function index(Request $request) {
    $keyword = $request->input('q', '');
    $results = [];

    if (strlen($keyword) >= 2) {
      $telegramUser = $this->getTelegramUser($request->telegram_id);
      $results = $this->searchService->search($telegramUser->id, $keyword, 50);
    }

    return view('fintech::web.search.index', compact('keyword', 'results'));
  }
}