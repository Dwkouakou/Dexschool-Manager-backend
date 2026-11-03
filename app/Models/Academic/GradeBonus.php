<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class GradeBonus extends Model
{
    use BelongsToEstablishment;

    protected $table = 'grade_bonuses';

    protected $fillable = ['student_id', 'classe_id', 'subject_id', 'period_id', 'bonus_average', 'bonus_points'];

    protected $casts = ['bonus_average' => 'float', 'bonus_points' => 'float'];
}