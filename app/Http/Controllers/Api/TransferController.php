<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Http\Requests\TransferRequest;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Services\TransferService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TransferController extends Controller
{
  protected TransferService $transferService;

  public function __construct(TransferService $transferService) {
    $this->transferService = $transferService;
  }

  public function index(): JsonResponse
  {
    $filters = request()->validate([
      'wallet_id' => 'nullable|exists:fintech_wallets,id',
      'per_page' => 'integer|min:1|max:100'
    ]);

    $perPage = request('per_page', 20);
    $result = $this->transferService->getTransfers(request()->user(), $filters, $perPage);

    return response()->json([
      'success' => true,
      'data' => $result['data'],
      'pagination' => $result['pagination'],
    ]);
  }

  public function store(TransferRequest $request): JsonResponse
  {
    try {
      $this->transferService->createTransfer($request->user(), $request->validated());
      return response()->json([
        'success' => true,
        'message' => 'Transfer berhasil dilakukan.'
      ], 201);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ], 400);
    }
  }

  public function update(TransferRequest $request, Transfer $transfer): JsonResponse
  {
    try {
      $this->transferService->updateTransfer($request->user(), $transfer, $request->validated());
      return response()->json([
        'success' => true,
        'message' => 'Transfer berhasil diperbarui.'
      ]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ], 400);
    }
  }

  public function destroy(Transfer $transfer): JsonResponse
  {
    try {
      $this->transferService->deleteTransfer(request()->user(), $transfer);
      return response()->json([
        'success' => true,
        'message' => 'Transfer dipindahkan ke tempat sampah.'
      ]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    }
  }

  public function trashed(): JsonResponse
  {
    $perPage = request('per_page', 20);
    $result = $this->transferService->getTrashedTransfers(request()->user(), $perPage);

    return response()->json([
      'success' => true,
      'data' => $result['data'],
      'pagination' => $result['pagination'],
    ]);
  }

  public function restore($id): JsonResponse
  {
    try {
      $this->transferService->restoreTransfer(request()->user(), $id);
      return response()->json([
        'success' => true,
        'message' => 'Transfer berhasil dipulihkan.'
      ]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage()
      ], 400);
    }
  }

  public function forceDelete($id): JsonResponse
  {
    try {
      $this->transferService->forceDeleteTransfer(request()->user(), $id);
      return response()->json([
        'success' => true,
        'message' => 'Transfer dihapus permanen.'
      ]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    }
  }
}