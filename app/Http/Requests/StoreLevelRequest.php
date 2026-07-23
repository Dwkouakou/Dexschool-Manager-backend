<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLevelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cycle_id' => ['required', 'exists:cycles,id'],

            'name' => ['required', 'string', 'max:100'],

            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('levels', 'code')
                    ->where(fn($q) => $q->where('cycle_id', $this->cycle_id)),
            ],

            'order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}