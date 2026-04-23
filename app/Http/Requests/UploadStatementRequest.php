<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadStatementRequest extends FormRequest
{
  /**
  * Indicates if the validator should stop on the first rule failure.
  *
  * @var bool
  */
  // protected $stopOnFirstFailure = true;

  /**
  * Get the validation rules that apply to the request.
  */
  public function rules(): array
  {
    \Log::debug($this->all());
    return [
      "wallet_id" => "required|exists:fintech_wallets,id",
      "file" => "required|file|max:10240|mimetypes:text/csv,text/plain,application/pdf,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/octet-stream,application/vnd.ms-office",
      "password" => "nullable|string|max:50",
    ];
  }

  /**
  * Determine if the user is authorized to make this request.
  */
  public function authorize(): bool
  {
    return true;
  }
}