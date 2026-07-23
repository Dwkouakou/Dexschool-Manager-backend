<?php

namespace App\Models\Academic;

use App\Models\Academic\AcademicYears;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Period extends Model
{
    //
     use HasFactory, BelongsToActiveYear , BelongsToEstablishment;

    protected $table = 'periods';

    protected $fillable = [
        'academic_year_id',
        'type',
        'name',
        'code',
        'start_date',
        'end_date',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'academic_year_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    // Relation avec l'année scolaire
    public function academicYear()
    {
        return $this->belongsTo(AcademicYears::class, 'academic_year_id');
    }
}
