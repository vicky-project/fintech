<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferRequest extends FormRequest
{
  public function authorize(): bool {
    return true;
  }

  public function rules(): array
  {
    $transfer = $this->route('transfer');

    $rules = [
      'amount' => 'required|numeric|min:0.01',
      'transfer_date' => 'required|date|before_or_equal:today',
      'description' => 'nullable|string|max:255',
    ];

    if ($transfer) {
      $rules['from_wallet_id'] = 'prohibited';
      $rules['to_wallet_id'] = 'prohibited';
    } else {
      $rules['from_wallet_id'] = [
        'required',
        Rule::exists('fintech_wallets', 'id')->where('user_id', $this->user()->id),
      ];
      $rules['to_wallet_id'] = [
        'required',
        'different:from_wallet_id',
        Rule::exists('fintech_wallets', 'id')->where('user_id', $this->user()->id),
      ];
    }

    return $rules;
  }
}