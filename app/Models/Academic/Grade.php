<?php

namespace App\Models\Academic;

use App\Models\Academic\Evaluation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Grade extends Model
{
    //

     use HasFactory;

    protected $table = 'grades';

    protected $fillable = ['evaluation_id', 'student_id', 'score', 'is_absent', 'teacher_comment'];

    protected $casts = [
        'score' => 'float',
        'is_absent' => 'boolean'
    ];

    public function evaluation()
    {
        return $this->belongsTo(Evaluation::class, 'evaluation_id');
    }

     protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('evaluation');
            }
        });
    }
}
