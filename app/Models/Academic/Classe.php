<?php

namespace App\Models\Academic;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Evaluation;
use App\Models\Academic\Level;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\officeAdministration\Enrollment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Classe extends Model
{
    //
    use HasFactory, BelongsToEstablishment , BelongsToActiveYear;

    protected $table = 'classes';

    protected $fillable = [
        'level_id',
        'academic_year_id',
        'name',
        'code',
        'capacity',
        'classroom',
        'main_teacher_id',
        'is_active',
        'establishment_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capacity' => 'integer',
        'level_id' => 'integer',
        'academic_year_id' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYears::class, 'academic_year_id');
    }

    public function enrollments()
    {
        // On lie la classe aux inscriptions via la clé étrangère 'class_id'
        return $this->hasMany(Enrollment::class, 'class_id');
    }

    public function subjectAttributions()
    {
        return $this->hasMany(ClassroomSubjectTeacher::class, 'classe_id');
    }

    // Récupérer toutes les évaluations (devoirs) de cette classe
    public function evaluations()
    {
        return $this->hasMany(Evaluation::class, 'classe_id');
    }

    // Récupérer toutes les notes obtenues par les élèves de cette classe via les évaluations
    public function grades()
    {
        return $this->hasManyThrough(Grade::class, Evaluation::class, 'classe_id', 'evaluation_id');
    }

}
