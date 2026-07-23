<?php

namespace App\Models\Transport;

use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Transport\Vehicle;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class VehicleExpense extends Model
{
    //
    use BelongsToEstablishment;
    protected $fillable = ['vehicle_id', 'title', 'amount', 'category', 'expense_date', 'description', 'created_by'];
    protected $casts = ['expense_date' => 'date', 'amount' => 'integer'];

    public function vehicle() { return $this->belongsTo(Vehicle::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
