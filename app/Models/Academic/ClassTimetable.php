<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class ClassTimetable extends Model
{
    use BelongsToEstablishment, BelongsToActiveYear;

    protected $fillable = ['academic_year_id', 'classe_id', 'is_published'];

    protected $casts = ['is_published' => 'boolean'];
}