<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WalletRequest extends FormRequest
{
  public function authorize(): bool
  {
    return true;
  }

  public function rules(): array
  {
    $wallet = $this->route('wallet');

    return [
      'name' => [
        'required',
        'string',
        'max:100',
        Rule::unique('fintech_wallets', 'name')
        ->where('user_id', $this->user()->id)
        ->ignore($wallet?->id),
      ],
      'currency' => [
        $wallet ? 'sometimes' : 'sometimes',
        'string',
        'size:3',
        'exists:world_currencies,code',
      ],
      'initial_balance' => [
        $wallet ? 'prohibited' : 'sometimes',
        'numeric',
        'min:0'
      ],
      'description' => 'nullable|string|max:255',
      'is_active' => 'sometimes|boolean',
    ];
  }

  public function messages(): array
  {
    return [
      'name.required' => 'Nama dompet wajib diisi.',
      'name.unique' => 'Anda sudah memiliki dompet dengan nama tersebut.',
      'currency.exists' => 'Mata uang tidak valid.',
      'initial_balance.prohibited' => 'Saldo awal tidak dapat diubah saat update.',
      'is_active.boolean' => 'Status aktif harus true atau false.',
    ];
  }
}