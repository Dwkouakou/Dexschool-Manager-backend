<?php

namespace App\Models\Academic;

use App\Models\Academic\PeriodAverage;
use App\Models\Personel\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ReportCard extends Model
{
    //

     use HasFactory;

    protected $table = 'report_cards';

    protected $fillable = [
        'period_average_id',
        'reference',
        'status',
        'file_path',
        'print_count',
        'last_printed_by',
        'last_printed_at'
    ];

    protected $casts = [
        'last_printed_at' => 'datetime',
        'print_count' => 'integer'
    ];

    // Relation vers la moyenne générale et le rang de la période
    public function periodAverage()
    {
        return $this->belongsTo(PeriodAverage::class, 'period_average_id');
    }

    // Le personnel ayant imprimé en dernier
    public function printer()
    {
        return $this->belongsTo(Employee::class, 'last_printed_by');
    }

    
       protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('periodAverage');
            }
        });
    }
}
