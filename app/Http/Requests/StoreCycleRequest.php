<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCycleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],

            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('cycles', 'code')
                    ->where(fn($q) => $q->where('establishment_id', $this->user()?->establishment_id)),
            ],

            'description' => ['nullable', 'string'],
            'order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'order'     => $this->integer('order') ?? 0,
        ]);
    }
}