<?php

namespace Modules\FinTech\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\FinTech\Http\Requests\WalletRequest;
use Modules\FinTech\Services\WalletService;
use Modules\FinTech\Models\Wallet;
use Brick\Money\Exception\MoneyMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WalletController extends Controller
{
  protected WalletService $walletService;

  public function __construct(WalletService $walletService) {
    $this->walletService = $walletService;
  }

  public function index(): JsonResponse
  {
    try {
      $wallets = $this->walletService->getUserWallets(request()->user());

      return response()->json([
        'success' => true,
        'data' => $wallets
      ]);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
      ], 500);
    }
  }

  public function store(WalletRequest $request): JsonResponse
  {
    try {
      $wallet = $this->walletService->createWallet($request->user(), $request->validated());

      return response()->json([
        'success' => true,
        'message' => 'Dompet berhasil dibuat',
        'data' => [
          'id' => $wallet->id,
          'name' => $wallet->name,
          'balance' => $wallet->getBalanceFloat(),
          'formatted_balance' => $wallet->getFormattedBalance(),
          'currency' => $wallet->currency,
        ]
      ], 201);
    } catch (MoneyMismatchException $e) {
      return response()->json([
        'success' => false,
        'message' => 'Mata uang tidak sesuai dengan dompet yang ada.'
      ], 422);
    } catch (\Exception $e) {
      return response()->json([
        'success' => false,
        'message' => 'Gagal membuat dompet: ' . $e->getMessage()
      ], 500);
    }
  }

  public function show(Wallet $wallet): JsonResponse
  {
    try {
      $data = $this->walletService->getWalletDetail(request()->user(), $wallet);

      return response()->json([
        'success' => true,
        'data' => $data
      ]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    } catch (\Exception $e) {
      return response()->json(['message' => 'Terjadi kesalahan'], 500);
    }
  }

  public function update(WalletRequest $request, Wallet $wallet): JsonResponse
  {
    try {
      $wallet = $this->walletService->updateWallet($request->user(), $wallet, $request->validated());

      return response()->json([
        'success' => true,
        'message' => 'Dompet berhasil diperbarui',
        'data' => [
          'id' => $wallet->id,
          'name' => $wallet->name,
          'balance' => $wallet->getBalanceFloat(),
          'formatted_balance' => $wallet->getFormattedBalance(),
          'is_active' => $wallet->is_active,
        ]
      ]);
    } catch (HttpException $e) {
      return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
    } catch (\Exception $e) {
      return response()->json(['message' => 'Gagal memperbarui dompet: ' . $e->getMessage()], 500);
    }
  }
}