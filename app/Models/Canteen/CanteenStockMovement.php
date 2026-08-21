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
        return $this->belongsTo(CanteenProduct::class, 'canteen_product_id');
    }

       protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                // ─── CORRECTIF : le scope pointait vers la relation
                // canteen_products() qui, faute de clé étrangère précisée,
                // faisait deviner à Eloquent la colonne
                // "canteen_products_id" (convention par défaut) au lieu de
                // la vraie colonne "canteen_product_id" (singulier, définie
                // dans la migration). Résultat : toute requête sur ce
                // modèle plantait avec "Unknown column
                // canteen_stock_movements.canteen_products_id". On utilise
                // maintenant product(), qui précise explicitement la bonne
                // clé étrangère — canteen_products() a aussi été corrigée
                // par cohérence, au cas où elle serait utilisée ailleurs.
                $builder->whereHas('product');
            }
        });
    }
}