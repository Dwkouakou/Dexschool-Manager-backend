<?php

namespace App\Models\Academic;

use App\Models\Academic\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class StudentParent extends Model
{
    protected $fillable = [
        'student_id',
        'type',
        'last_name',
        'first_name',
        'phone',
        'phone_alt',
        'email',
        'profession',
        'employer',
        'district',
        'address',
        'photo',
        'is_emergency_contact',
        'is_main_contact',
    ];

    protected $casts = [
        'is_emergency_contact' => 'boolean',
        'is_main_contact'      => 'boolean',
    ];

    /**
     * Scope multi-tenant : filtre les parents via l'établissement du student.
     * (student_parents n'a pas de colonne establishment_id → on passe par le student)
     */
    // protected static function booted(): void
    // {
    //     static::addGlobalScope('establishment', function (Builder $builder) {
    //         $user = Auth::user();
    //         if ($user && !empty($user->establishment_id)) {
    //             $builder->whereHas('student', function ($q) use ($user) {
    //                 $q->where('students.establishment_id', $user->establishment_id);
    //             });
    //         }
    //     });
    // }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

     protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('student');
            }
        });
    }
}