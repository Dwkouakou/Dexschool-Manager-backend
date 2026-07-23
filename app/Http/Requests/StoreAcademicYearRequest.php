<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAcademicYearRequest extends FormRequest
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
            'name' => [
                'required',
                'string',
                'max:100',
                // Unicité du nom d'année scopée par établissement :
                // deux écoles peuvent avoir "2025-2026", mais pas deux fois dans la même
                Rule::unique('academic_years', 'name')
                    ->where(fn($q) => $q->where('establishment_id', $this->user()?->establishment_id)),
            ],

            'start_date'  => ['required', 'date'],
            'end_date'    => ['required', 'date', 'after:start_date'],
            'is_active'   => ['nullable', 'boolean'],
            'is_archived' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Messages d'erreur personnalisés.
     */
    public function messages(): array
    {
        return [
            'name.required'       => "Le libellé de l'année scolaire est obligatoire.",
            'name.unique'         => "Une année scolaire portant ce nom existe déjà dans votre établissement.",
            'start_date.required' => "La date de début est obligatoire.",
            'end_date.required'   => "La date de fin est obligatoire.",
            'end_date.after'      => "La date de fin doit être postérieure à la date de début.",
        ];
    }

    /**
     * Normalise les booléens avant validation (utile si envoyés en 0/1 ou "true"/"false").
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'is_active'   => $this->boolean('is_active'),
            'is_archived' => $this->boolean('is_archived'),
        ]);
    }
}