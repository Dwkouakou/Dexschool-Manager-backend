<?php

namespace App\Models\Transport;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Transport\TransportSubscription;
use App\Models\Transport\Vehicle;
use Illuminate\Database\Eloquent\Model;

class TransportRoute extends Model
{
    //
    use BelongsToEstablishment;
    protected $fillable = ['vehicle_id', 'name', 'departure_point', 'arrival_point', 'stops_circuit', 'monthly_fee', 'is_active'];
    protected $casts = ['monthly_fee' => 'integer', 'is_active' => 'boolean'];

    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function subscriptions() { return $this->hasMany(TransportSubscription::class, 'route_id'); }
    
}
