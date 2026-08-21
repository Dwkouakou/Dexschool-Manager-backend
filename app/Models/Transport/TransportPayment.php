<?php

namespace App\Models\Transport;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Transport\TransportSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TransportPayment extends Model
{
    use BelongsToEstablishment;

    protected $fillable = [
        'subscription_id', 'receipt_number', 'amount_paid',
        'payment_date', 'payment_method', 'notes', 'created_by',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount_paid'  => 'integer',
    ];

    public function subscription() { return $this->belongsTo(TransportSubscription::class, 'subscription_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}