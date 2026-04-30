<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatchStoreClientBalanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.apart_id' => ['required', 'integer'],
            'items.*.year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'items.*.month' => ['required', 'integer', 'between:1,12'],
            'items.*.balance' => ['required', 'numeric'],
        ];
    }
}
