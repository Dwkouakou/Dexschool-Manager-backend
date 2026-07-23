<?php

namespace App\Models\Canteen;

use App\Models\Canteen\CanteenSubscription;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class MealType extends Model
{
    //
    use BelongsToEstablishment;
     protected $fillable = ['name', 'code', 'description', 'price_per_month', 'is_active'];
    protected $casts = ['price_per_month' => 'integer', 'is_active' => 'boolean'];

    public function subscriptions() {
        return $this->hasMany(CanteenSubscription::class, 'meal_type_id');
    }
}
