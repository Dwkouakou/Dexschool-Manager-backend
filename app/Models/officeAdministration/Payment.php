<?php

namespace App\Models\officeAdministration;

use App\Models\officeAdministration\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Payment extends Model
{
    //
      use HasFactory, SoftDeletes;

    protected $fillable = [
        'enrollment_id',
        'receipt_number',
        'amount_paid',
        'payment_date',
        'payment_method',
        'transaction_reference',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid'  => 'integer',
    ];

    /**
     * Relation : Un paiement appartient à une inscription spécifique.
     */
    public function enrollment()
    {
        return $this->belongsTo(Enrollment::class, 'enrollment_id');
    }

    /**
     * Relation : Le paiement a été encaissé par un utilisateur (caissier).
     */
    public function caissier()
    {
        return $this->belongsTo(User::class, 'created_by');
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
