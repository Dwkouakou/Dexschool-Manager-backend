<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class TimeSlot extends Model
{
    use BelongsToEstablishment;

    public const TYPES = ['cours', 'recreation', 'apres_midi'];

    protected $fillable = ['start_time', 'end_time', 'type'];
}