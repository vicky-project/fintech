<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    return [
      'from_wallet_id' => [
        'required',
        Rule::exists('fintech_wallets', 'id')->where('user_id', $this->user()->id),
      ],
      'to_wallet_id' => [
        'required',
        'different:from_wallet_id',
        Rule::exists('fintech_wallets', 'id')->where('user_id', $this->user()->id),
      ],
      'amount' => 'required|numeric|min:0.01',
      'description' => 'nullable|string|max:255',
      'transaction_date' => 'required|date|before_or_equal:today',
    ];
  }

  public function messages(): array
  {
    return [
      'from_wallet_id.required' => 'Dompet asal harus dipilih.',
      'to_wallet_id.required' => 'Dompet tujuan harus dipilih.',
      'to_wallet_id.different' => 'Dompet tujuan harus berbeda dengan dompet asal.',
      'amount.min' => 'Jumlah transfer minimal 0.01.',
    ];
  }
}