<?php

namespace App\Models\Transport;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Personel\Employee;
use App\Models\Transport\TransportRoute;
use App\Models\Transport\VehicleExpense;
use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    //
    use BelongsToEstablishment;
     protected $fillable = ['driver_id', 'name', 'registration_number', 'brand', 'model', 'capacity', 'purchase_date', 'is_active'];
    protected $casts = ['purchase_date' => 'date', 'capacity' => 'integer', 'is_active' => 'boolean'];

    public function driver() { return $this->belongsTo(Employee::class, 'driver_id'); }
    public function routes() { return $this->hasMany(TransportRoute::class); }
    public function expenses() { return $this->hasMany(VehicleExpense::class); }
}
