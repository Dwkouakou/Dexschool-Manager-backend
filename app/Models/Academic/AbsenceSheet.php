<?php

namespace App\Models\Academic;

use App\Models\Academic\AttendanceRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AbsenceSheet extends Model
{
    //
     use HasFactory;

    protected $table = 'absence_sheets';

    protected $fillable = ['classe_id', 'subject_id', 'employee_id', 'period_id', 'date', 'time_slot'];

    protected $casts = ['date' => 'date'];

    public function records()
    {
        return $this->hasMany(AttendanceRecord::class, 'absence_sheet_id');
    }

         public function classe()
    {
        return $this->belongsTo(Classe::class, 'classe_id');
    }

          protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('classe');
            }
        });
    }
}
