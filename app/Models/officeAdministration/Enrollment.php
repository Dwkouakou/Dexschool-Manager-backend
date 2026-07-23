<?php

namespace App\Models\officeAdministration;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\officeAdministration\EnrollmentFinancial;
use App\Models\officeAdministration\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class Enrollment extends Model
{
    //
     use HasFactory, SoftDeletes , BelongsToEstablishment , BelongsToActiveYear;

    protected $fillable = [
        'student_id',
        'class_id',
        'academic_year_id',
        'enrollment_number',
        'type',
        'status',
        'enrollment_date',
        'notes',
        'created_by',
        'validated_by',
        'validated_at',
        'establishment_id',

    ];

    protected $casts = [
        'enrollment_date' => 'date',
    ];

    // RELATION : Accès aux détails financiers
    public function financial()
    {
        return $this->hasOne(EnrollmentFinancial::class, 'enrollment_id');
    }

    // RELATION INVERSE : Appartient à un élève
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    // RELATION : Lié à une classe
    public function classe()
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }

    // RELATION : Lié à une année académique
    public function academicYear()
    {
        return $this->belongsTo(AcademicYears::class, 'academic_year_id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'enrollment_id');
    }

      public function validator()
    {
        // On précise à Laravel que la clé étrangère s'appelle 'validated_by'
        return $this->belongsTo(User::class, 'validated_by');
    }

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
}
