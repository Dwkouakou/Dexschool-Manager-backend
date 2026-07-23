<?php

namespace App\Models\Academic;

use App\Models\Academic\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PeriodAverage extends Model
{
    //
     protected $table = 'period_averages';
    protected $fillable = [
        'student_id', 'classe_id', 'period_id', 'general_average', 'rank', 
        'class_highest_average', 'class_lowest_average', 'class_average', 'council_appreciation', 'is_validated'
    ];

    public function period() { return $this->belongsTo(Period::class); }

    public function reportCards () {
        return $this->hasMany(ReportCard::class);
    }

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
