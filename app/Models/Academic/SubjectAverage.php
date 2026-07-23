<?php

namespace App\Models\Academic;

use App\Models\Academic\Period;
use App\Models\Academic\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SubjectAverage extends Model
{
    //
    protected $table = 'subject_averages';
    protected $fillable = ['student_id', 'classe_id', 'subject_id', 'period_id', 'average', 'rank', 'teacher_appreciation'];

    public function subject() { return $this->belongsTo(Subject::class); }
    public function period() { return $this->belongsTo(Period::class); }

    public function student () {
        return $this->belongsTo(Student::class, 'student_id');
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
