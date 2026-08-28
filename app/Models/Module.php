<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $fillable = ['key', 'label', 'description', 'is_active_by_default'];

    protected $casts = [
        'is_active_by_default' => 'boolean',
    ];

    public function establishmentOverrides() {
        return $this->hasMany(EstablishmentModuleAccess::class);
    }
}