<?php

namespace App\Models\officeAdministration;

use App\Models\officeAdministration\Enrollment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EnrollmentFinancial extends Model
{
    //

      use HasFactory;

    protected $table = 'enrollment_financials';

    protected $fillable = [
        
        'enrollment_id',
        'registration_fee',
        'tuition_fee',
        'annex_fee',
        'discount_amount',
        'previous_balance',
        'total_due',
        'initial_payment',
        'payment_method',
    ];

    protected $casts = [
        'registration_fee' => 'integer',
        'tuition_fee'      => 'integer',
        'annex_fee'        => 'integer',
        'discount_amount'  => 'integer',
        'previous_balance' => 'integer',
        'total_due'        => 'integer',
        'initial_payment'  => 'integer',
    ];

    // RELATION INVERSE : Rattrapage de l'inscription mère
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('enrollment');
            }
        });
    }
}
