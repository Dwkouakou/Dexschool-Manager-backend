<?php

namespace App\Models\Canteen;

use App\Models\Canteen\CanteenSubscription;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CanteenPayment extends Model
{
    //
    use BelongsToEstablishment;

    protected $fillable = [
        'establishment_id',
        'canteen_subscription_id',
        'amount',
        'remaining_after', // Reste à payer figé juste après ce versement
        'payment_date',
        'period_covered',  // Début de la période de scolarité couverte
        'period_end',      // Fin de la période de scolarité couverte
        'type',            // 'payment' (versement classique) ou 'renewal' (versement lors d'un renouvellement)
        'receipt_number',  // ex: CT-DSM-2026-00001
        'collected_by',
        'notes',
    ];

    protected $casts = [
        'amount'           => 'integer',
        'remaining_after'  => 'integer',
        'payment_date'     => 'date',
        'period_covered'   => 'date',
        'period_end'       => 'date',
    ];

    public function subscription() {
        return $this->belongsTo(CanteenSubscription::class, 'canteen_subscription_id');
    }

    public function collector() {
        return $this->belongsTo(User::class, 'collected_by');
    }
}