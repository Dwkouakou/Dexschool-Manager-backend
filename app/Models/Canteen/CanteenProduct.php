<?php

namespace App\Models\Canteen;

use App\Models\Canteen\CanteenStockMovement;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class CanteenProduct extends Model
{
    //
    use BelongsToEstablishment;
    protected $fillable = ['name', 'unit', 'current_stock', 'alert_threshold'];
    protected $casts = ['current_stock' => 'integer', 'alert_threshold' => 'integer'];

    public function movements() { return $this->hasMany(CanteenStockMovement::class); }
}
