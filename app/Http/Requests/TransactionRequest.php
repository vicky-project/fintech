<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\FinTech\Enums\TransactionType;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    $transaction = $this->route('transaction');

    $rules = [
      'wallet_id' => [
        $transaction ? 'prohibited' : 'required',
        Rule::exists('fintech_wallets', 'id')->where(function ($query) {
          $query->where('user_id', $this->user()->id);
        }),
      ],
      'category_id' => 'required|exists:fintech_categories,id',
      'type' => ['required',
        Rule::in(TransactionType::values())],
      'amount' => 'required|numeric|min:0.01',
      'transaction_date' => 'required|date|before_or_equal:today',
      'description' => 'nullable|string|max:255',
    ];

    if ($transaction) {
      $rules['wallet_id'] = 'prohibited';
    }

    return $rules;
  }

  public function messages(): array
  {
    return [
      'wallet_id.required' => 'Dompet harus dipilih.',
      'wallet_id.prohibited' => 'Dompet tidak dapat diubah setelah transaksi dibuat.',
      'category_id.required' => 'Kategori harus dipilih.',
      'category_id.exists' => 'Kategori tidak valid.',
      'type.required' => 'Tipe transaksi harus dipilih.',
      'type.in' => 'Tipe transaksi tidak valid.',
      'amount.required' => 'Jumlah harus diisi.',
      'amount.min' => 'Jumlah minimal 0.01.',
      'transaction_date.required' => 'Tanggal harus diisi.',
      'transaction_date.before_or_equal' => 'Tanggal tidak boleh lebih dari hari ini.',
    ];
  }
}