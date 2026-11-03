<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class ScheduleSetting extends Model
{
    use BelongsToEstablishment;

    protected $fillable = ['days'];

    protected $casts = ['days' => 'array'];
}