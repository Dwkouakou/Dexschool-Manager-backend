<?php

namespace App\Models\Academic;

use App\Models\Academic\Classe;
use App\Models\Academic\Grade;
use App\Models\Academic\Period;
use App\Models\Academic\Subject;
use App\Models\Personel\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Evaluation extends Model
{
    //
    use HasFactory;

    protected $table = 'evaluations';

    protected $fillable = ['classe_id', 'subject_id', 'employee_id', 'period_id', 'title', 'type', 'date', 'max_score'];

    protected $casts = ['date' => 'date', 'max_score' => 'integer'];

    // Récupérer toutes les notes associées à ce devoir
    public function grades()
    {
        return $this->hasMany(Grade::class, 'evaluation_id');
    }

        public function classe()
    {
        return $this->belongsTo(Classe::class, 'classe_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function period()
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function teacher()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
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
