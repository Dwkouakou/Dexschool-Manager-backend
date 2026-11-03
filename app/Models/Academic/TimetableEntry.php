<?php

namespace App\Models\Academic;

use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class TimetableEntry extends Model
{
    use BelongsToEstablishment, BelongsToActiveYear;

    protected $fillable = ['academic_year_id', 'classe_id', 'time_slot_id', 'day', 'subject_id', 'room_id'];
}