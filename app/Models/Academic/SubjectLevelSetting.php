<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class SubjectLevelSetting extends Model
{
    use BelongsToEstablishment;

    protected $table = 'subject_level_settings';

    protected $fillable = ['subject_id', 'level_id', 'coefficient', 'excluded_from_report'];

    protected $casts = [
        'coefficient'          => 'float',
        'excluded_from_report' => 'boolean',
    ];
}