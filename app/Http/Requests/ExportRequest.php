<?php

namespace Modules\FinTech\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportRequest extends FormRequest
{
  /**
  * Tentukan apakah user berhak melakukan request ini.
  */
  public function authorize(): bool
  {
    // Middleware auth sudah memastikan user login
    return true;
  }

  /**
  * Aturan validasi untuk request.
  */
  public function rules(): array
  {
    return [
      'type' => ['required',
        Rule::in(['transactions', 'transfers', 'budgets', 'all'])],
      'format' => ['required',
        Rule::in(['pdf', 'xlsx', 'csv', 'gsheet'])],

      'wallet_id' => [
        'required',
        Rule::exists('fintech_wallets', 'id')->where(function ($query) {
          $query->where('user_id', $this->user()->id);
        }),
      ],

      'date_from' => ['nullable',
        'date'],
      'date_to' => ['nullable',
        'date',
        'after_or_equal:date_from'],
      'month' => ['nullable',
        'date_format:Y-m'],

      // Transactions
      'transaction_type' => ['nullable',
        Rule::in(['income', 'expense'])],
      'category_ids' => ['nullable',
        'array'],
      'category_ids.*' => ['exists:fintech_categories,id'],
      'include_description' => ['nullable',
        'boolean'],

      // Budgets
      'period_type' => ['nullable',
        Rule::in(['monthly', 'yearly'])],
      'year' => ['nullable',
        'digits:4',
        'integer',
        'min:2000',
        'max:' . now()->year],
      'status' => ['nullable',
        Rule::in(['overspent', 'near_limit', 'on_track'])],

      // Transfers (menggunakan wallet_id, date_from, date_to, month yang sama)
    ];
  }

  /**
  * Custom message untuk setiap rule.
  */
  public function messages(): array
  {
    return [
      'type.required' => 'Jenis data wajib dipilih.',
      'type.in' => 'Jenis data harus salah satu dari: transaksi, transfer, atau budget.',

      'format.required' => 'Format file wajib dipilih.',
      'format.in' => 'Format file harus PDF atau Excel (.xlsx).',

      'wallet_id.exists' => 'Dompet yang dipilih tidak ditemukan atau bukan milik Anda.',

      'date_from.date' => 'Format tanggal mulai tidak valid.',
      'date_to.date' => 'Format tanggal akhir tidak valid.',
      'date_to.after_or_equal' => 'Tanggal akhir harus setelah atau sama dengan tanggal mulai.',

      'month.date_format' => 'Format bulan harus YYYY-MM (contoh: 2025-06).',

      'transaction_type.in' => 'Tipe transaksi harus Pemasukan atau Pengeluaran.',

      'category_ids.array' => 'Kategori harus berupa array.',
      'category_ids.*.exists' => 'Salah satu kategori yang dipilih tidak ditemukan.',

      // Budgets
      'period_type.in' => 'Tipe periode budget harus Bulanan atau Tahunan.',
      'year.digits' => 'Tahun harus 4 digit.',
      'year.integer' => 'Tahun harus berupa angka.',
      'year.min' => 'Tahun minimal 2000.',
      'year.max' => 'Tahun maksimal tahun ini.',
      'status.in' => 'Status budget harus salah satu dari: terlampaui, mendekati, atau aman.',
    ];
  }
}