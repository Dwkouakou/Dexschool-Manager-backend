<?php

namespace App\Models\Academic;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\Student;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class StudentAcademicRecord extends Model
{
    use BelongsToEstablishment, BelongsToActiveYear; 

    protected $fillable = [
        'establishment_id', 'student_id', 'academic_year_id', 'class_id',
        'is_repeater', 'year_end_decision', 'lv2', 'art',
    ];

    protected $casts = [
        'is_repeater' => 'boolean',
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function classe() { return $this->belongsTo(Classe::class, 'class_id'); }
    public function academicYear() { return $this->belongsTo(AcademicYears::class, 'academic_year_id'); }
}