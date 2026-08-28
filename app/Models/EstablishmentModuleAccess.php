<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EstablishmentModuleAccess extends Model
{
    protected $table = 'establishment_module_access';

    protected $fillable = ['establishment_id', 'module_id', 'is_enabled', 'updated_by'];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];

    public function establishment() {
        return $this->belongsTo(Establishment::class);
    }

    public function module() {
        return $this->belongsTo(Module::class);
    }

    public function updater() {
        return $this->belongsTo(User::class, 'updated_by');
    }
}