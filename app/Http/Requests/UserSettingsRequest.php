<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\FinTech\Models\UserSetting;

class UserSettingsRequest extends FormRequest
{
  /**
  * Tentukan apakah user berhak melakukan request ini.
  */
  public function authorize(): bool
  {
    return true;
  }

  /**
  * Aturan validasi untuk request.
  */
  public function rules(): array
  {
    $settings = UserSetting::where('user_id', $this->id())->first();
    $hasExistingPin = $settings && !empty($settings->pin);

    return [
      'default_currency' => [
        'sometimes',
        'string',
        'size:3',
        Rule::exists('world_currencies', 'code'),
      ],
      'default_wallet_id' => [
        'sometimes',
        'nullable',
        Rule::exists('fintech_wallets', 'id')->where(function ($query) {
          $query->where('user_id', $this->user()->id);
        }),
      ],
      'pin_enabled' => 'sometimes|boolean',
      'pin' => [
        "nullable",
        "string",
        "min:4",
        "max:6",
        function($attribute, $value, $fail) use($hasExistingPin) {
          if ($this->boolean('pin_enabled') && !$hasExistingPin && empty($value)) {
            $fail("PIN wajib diisi jika diaktifkan");
          }
        }],
      'notification_telegram' => 'sometimes|boolean',
    ];
  }

  /**
  * Data yang telah tervalidasi dan melalui pemrosesan tambahan.
  */
  public function validatedSettings(): array
  {
    $validated = $this->validated();

    // Jika pin_enabled false, pastikan pin dikosongkan
    if (isset($validated['pin_enabled']) && !$validated['pin_enabled']) {
      $validated['pin'] = null;
    }

    return $validated;
  }

  public function messages(): array
  {
    return [
      'default_wallet_id.exists' => "Dompet tidak ditemukan.",
      'pin.min' => 'Minimal 4 digit',
      'pin.max' => 'Maksimal 6 digit',
    ];
  }
}