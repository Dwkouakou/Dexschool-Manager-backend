<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class StoreClassesValidation extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            //
            'level_id'          => ['required', 'exists:levels,id'],
            'academic_year_id'  => ['required', 'exists:academic_years,id'],
            'name'              => ['required', 'string', 'max:100'],
            'capacity'          => ['required', 'integer', 'min:1', 'max:255'],
            'classroom'         => ['nullable', 'string', 'max:100'],
            'is_active'         => ['required', 'boolean'],
            'code'              => [
                'required',
                'string',
                'max:20',
                // CORRECTION : Empêche d'avoir le même code de classe dans le même niveau pour la même année scolaire
                Rule::unique('classes')->where(function ($query) {
                    return $query->where('level_id', $this->level_id)
                                 ->where('academic_year_id', $this->academic_year_id);
                })
            ],

        ];
    }

      protected function prepareForValidation()
    {
        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'capacity'  => intval($this->capacity),
        ]);
    }
}
