<?php

namespace App\Models\Academic;

use App\Models\Academic\AbsenceSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AttendanceRecord extends Model
{
    //

     use HasFactory;

    protected $table = 'attendance_records';

    protected $fillable = ['absence_sheet_id', 'student_id', 'status', 'is_justified', 'justification_reason', 'justified_by'];

    protected $casts = ['is_justified' => 'boolean'];

    public function absenceSheet()
    {
        return $this->belongsTo(AbsenceSheet::class, 'absence_sheet_id');
    }

     protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('absenceSheet');
            }
        });
    }
}
