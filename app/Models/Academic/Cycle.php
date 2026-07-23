<?php

namespace App\Models\Academic;

use App\Models\Academic\Level;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cycle extends Model
{
    //

    use HasFactory, BelongsToEstablishment;

     protected $table = 'cycles';

      protected $fillable = [
        'establishment_id',
        'name',
        'code',
        'description',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer'
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS
    |--------------------------------------------------------------------------
    */

    public function levels()
    {
        return $this->hasMany(Level::class);
    }
}
