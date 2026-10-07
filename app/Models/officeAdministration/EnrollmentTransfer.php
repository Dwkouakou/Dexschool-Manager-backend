<?php

namespace App\Models\officeAdministration;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Historique des transferts de classe (guichet de transfert).
 * Chaque ligne = un mouvement : ancienne classe -> nouvelle classe, avec
 * motif, auteur et date. Jamais supprimé (traçabilité).
 */
class EnrollmentTransfer extends Model
{
    use BelongsToEstablishment;

    protected $fillable = [
        'establishment_id', 'enrollment_id', 'student_id', 'academic_year_id',
        'from_class_id', 'to_class_id', 'reason', 'transferred_by', 'transferred_at',
    ];

    protected $casts = ['transferred_at' => 'datetime'];

    public function enrollment()   { return $this->belongsTo(Enrollment::class, 'enrollment_id')->withTrashed(); }
    public function student()      { return $this->belongsTo(Student::class, 'student_id')->withTrashed(); }
    public function fromClass()    { return $this->belongsTo(Classe::class, 'from_class_id'); }
    public function toClass()      { return $this->belongsTo(Classe::class, 'to_class_id'); }
    public function academicYear() { return $this->belongsTo(AcademicYears::class, 'academic_year_id'); }
    public function author()       { return $this->belongsTo(User::class, 'transferred_by'); }
}