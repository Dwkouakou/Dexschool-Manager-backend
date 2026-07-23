<?php

namespace App\Models\Canteen;

use App\Models\Canteen\CanteenProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CanteenStockMovement extends Model
{
    //
    protected $fillable = ['canteen_product_id', 'type', 'quantity', 'reason', 'movement_date', 'created_by'];
    protected $casts = ['movement_date' => 'date', 'quantity' => 'integer'];

    public function product() { return $this->belongsTo(CanteenProduct::class, 'canteen_product_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function canteen_products() {
        return $this->belongsTo(CanteenProduct::class);
    }

       protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('canteen_products');
            }
        });
    }
}
