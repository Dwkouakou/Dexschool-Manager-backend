<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StudentParentRequest extends FormRequest
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
            'student_id'           => 'required|exists:students,id',
            'type'                 => 'required|in:father,mother,guardian',
            'last_name'            => 'required|string|max:100',
            'first_name'           => 'required|string|max:100',
            'phone'                => 'required|string|max:30',
            'phone_alt'            => 'nullable|string|max:30',
            'email'                => 'nullable|email|max:255',
            'profession'           => 'nullable|string|max:150',
            'employer'             => 'nullable|string|max:150',
            'district'             => 'nullable|string|max:150',
            'address'              => 'nullable|string',
            'photo'                => 'nullable|image|mimes:jpeg,png,jpg|max:2048', // Validation binaire multipart comme l'élève
            'is_emergency_contact' => 'required|in:0,1,true,false', // Sécurité booléen FormData
            'is_main_contact'      => 'required|in:0,1,true,false', // Sécurité booléen FormData
        ];
    }
}
