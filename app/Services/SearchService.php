<?php

namespace Modules\FinTech\Services;

use Modules\FinTech\Models\Transaction;
use Modules\FinTech\Models\Transfer;
use Modules\FinTech\Models\BankStatement;

class SearchService
{
  public function search(int $userId, string $keyword, int $limit = 20): array
  {
    // Pencarian transaksi
    $transactions = Transaction::with(['wallet', 'category'])
    ->whereHas('wallet', fn($q) => $q->where('user_id', $userId))
    ->where(function ($q) use ($keyword) {
      $q->where('description', 'like', "%{$keyword}%")
      ->orWhereHas('category', fn($c) => $c->where('name', 'like', "%{$keyword}%"))
      ->orWhere('amount', 'like', "%{$keyword}%");
    })
    ->orderBy('transaction_date', 'desc')
    ->limit($limit)
    ->get()
    ->map(fn($t) => [
      'type' => 'transaction',
      'id' => $t->id,
      'date' => $t->transaction_date->toDateString(),
      'description' => $t->description,
      'amount' => $t->getFormattedAmount(),
      'category' => $t->category->name,
      'wallet' => $t->wallet->name,
      'icon' => $t->category->icon,
      'color' => $t->category->color,
      'transaction_type' => $t->type->value,
    ]);

    // Pencarian transfer
    $transfers = Transfer::with(['fromWallet', 'toWallet'])
    ->where(function ($q) use ($userId) {
      $q->whereHas('fromWallet', fn($q) => $q->where('user_id', $userId))
      ->orWhereHas('toWallet', fn($q) => $q->where('user_id', $userId));
    })
    ->where(function ($q) use ($keyword) {
      $q->where('description', 'like', "%{$keyword}%")
      ->orWhere('amount', 'like', "%{$keyword}%");
    })
    ->orderBy('transfer_date', 'desc')
    ->limit($limit)
    ->get()
    ->map(fn($t) => [
      'type' => 'transfer',
      'id' => $t->id,
      'date' => $t->transfer_date->toDateString(),
      'description' => $t->description ?: "Transfer {$t->fromWallet->name} → {$t->toWallet->name}",
      'amount' => $t->getFormattedAmount(),
      'from_wallet' => $t->fromWallet->name,
      'to_wallet' => $t->toWallet->name,
      'icon' => 'bi-arrow-left-right',
      'color' => '#17a2b8',
    ]);

    // Pencarian statement
    $statements = BankStatement::with('wallet')
    ->where('user_id', $userId)
    ->where(function ($q) use ($keyword) {
      $q->where('original_filename', 'like', "%{$keyword}%")
      ->orWhere('bank_code', 'like', "%{$keyword}%");
    })
    ->orderBy('created_at', 'desc')
    ->limit($limit)
    ->get()
    ->map(fn($s) => [
      'type' => 'statement',
      'id' => $s->id,
      'date' => $s->created_at->toDateString(),
      'description' => $s->original_filename,
      'bank_code' => $s->bank_code,
      'wallet' => $s->wallet->name ?? '-',
      'status' => $s->status->label(),
      'icon' => 'bi-file-text',
      'color' => '#6c757d',
    ]);

    // Gabungkan hasil
    return $transactions->concat($transfers)->concat($statements)->values()->toArray();
  }
}