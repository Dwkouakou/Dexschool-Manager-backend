<?php

namespace App\Models\Academic;

use App\Models\Academic\Classe;
use App\Models\Academic\Subject;
use App\Models\Personel\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ClassroomSubjectTeacher extends Model
{
    //
      protected $table = 'classroom_subject_teachers';

    protected $fillable = [
        'employee_id',
        'classe_id',
        'subject_id',
        'coefficient',
    ];

    protected $casts = [
        'coefficient' => 'integer',
        'employee_id' => 'integer',
        'classe_id' => 'integer',
        'subject_id' => 'integer',
    ];

    // Relation pour récupérer l'Enseignant
    public function teacher()
    {
        // Remplacez 'Employee::class' par votre modèle de personnel si différent
        return $this->belongsTo(Employee::class, 'employee_id'); 
    }

    // Relation pour récupérer la Classe
    public function classe()
    {
        return $this->belongsTo(Classe::class, 'classe_id');
    }

    // Relation pour récupérer la Matière
    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('classe');
            }
        });
    }
}
