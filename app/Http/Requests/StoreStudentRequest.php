<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Champs students — identité
            'last_name'             => 'required|string|max:100',
            'first_name'            => 'required|string|max:150',
            'gender'                => 'required|in:M,F',
            'birth_date'            => 'required|date|before:today',
            'birth_place'           => 'required|string|max:100',
            'nationality'           => 'nullable|string|max:100',
            'national_matricule'    => 'nullable|string|max:50',
            'provisional_matricule' => 'nullable|string|max:50',
            'origin_school'         => 'nullable|string|max:200',
            'phone'                 => 'nullable|string|max:25',
            'email'                 => 'nullable|email|max:100',
            'address'               => 'nullable|string|max:255',
            'class_id'              => 'required|integer|exists:classes,id',
            'academic_year_id'      => 'required|integer|exists:academic_years,id',
            'photo'                 => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'is_active'             => 'required|in:0,1,true,false',
            'is_transferred'        => 'nullable|in:0,1,true,false',
            'assignment_status'     => 'nullable|in:affecte,non_affecte',

            // Champs du record de l'année (student_academic_records)
            'lv2'                   => 'nullable|string|max:50',
            'art'                   => 'nullable|string|max:50',
            'is_repeater'           => 'nullable|in:0,1,true,false',

            // Champs tuteur (student_parents)
            'tutor_name'            => 'nullable|string|max:150',
            'tutor_phone'           => 'nullable|string|max:30',
        ];
    }

    public function messages(): array
    {
        return [
            'last_name.required'   => 'Le nom de famille est obligatoire.',
            'first_name.required'  => 'Le prénom est obligatoire.',
            'gender.required'      => 'Le genre (Fille/Garçon) est obligatoire.',
            'gender.in'            => 'Le genre sélectionné est invalide.',
            'birth_date.required'  => 'La date de naissance est obligatoire.',
            'birth_date.before'    => 'La date de naissance doit être une date passée.',
            'birth_place.required' => 'Le lieu de naissance est obligatoire.',
            'class_id.required'    => 'Veuillez assigner une classe à l\'élève.',
            'class_id.exists'      => 'La classe sélectionnée n\'existe pas.',
            'academic_year_id.required' => 'L\'année académique est obligatoire.',
            'academic_year_id.exists'   => 'L\'année académique sélectionnée n\'existe pas.',
            'photo.image'          => 'Le fichier doit être une image.',
            'photo.mimes'          => 'La photo doit être au format jpeg, png ou jpg.',
            'photo.max'            => 'La photo ne doit pas dépasser 2 Mo.',
            'assignment_status.in' => 'Le statut d\'affectation sélectionné est invalide.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => 'error',
            'message' => 'Certains champs comportent des erreurs de validation.',
            'errors'  => $validator->errors()
        ], 422));
    }
}