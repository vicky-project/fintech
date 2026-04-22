<?php
namespace Modules\FinTech\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class UploadStatementRequest extends FormRequest
{
  /**
  * Get the validation rules that apply to the request.
  */ public function rules(): array
  {
    return [
      "wallet_id" => "required|exists:fintech_wallets,id",
      "file" => [
        "required",
        "file",
        Rule::file()->types([
          "text/csv",
          "text/plain",
          "application/pdf",
          "application/vnd.ms-excel",
          "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ]),
      ],
      "password" => "nullable|string|max:50",
    ];
  }

  /**
  * Determine if the user is authorized to make this request.            */
  public function authorize(): bool
  {
    return true;
  }

  /**
  * Get the error messages for the defined validation rules.
  *
  * @return array<string, string>
  */
  public function messages(): array
  {
    return [
      "file.required" => "The file is required",
      "file.file" => "The file was failed to upload",
      "file.mimetypes" =>
      "File support available now: PDF, Excel, Spreadsheet, and CSV types",
    ];
  }
}