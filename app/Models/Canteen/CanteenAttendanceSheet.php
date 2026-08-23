<?php

namespace App\Models\Canteen;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CanteenAttendanceSheet extends Model
{
    //
    use BelongsToEstablishment;

    protected $fillable = [
        'establishment_id',
        'attendance_date',
        'submitted_by',
        'submitted_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'submitted_at'    => 'datetime',
    ];

    public function submitter() {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}