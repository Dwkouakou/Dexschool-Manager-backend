<?php

namespace App\Models\Personel;

use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Positions extends Model
{
    //
    use HasFactory, BelongsToEstablishment;

    /**
     * Les attributs assignables en masse.
     */
    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'establishment_id', 
    ];

    /**
     * Les attributs à transtyper (Casting).
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Relation : Un poste (ex: Enseignant) est attribué à plusieurs employés.
     */
    public function employees()
    {
        return $this->hasMany(Employee::class, 'position_id');
    }
}
