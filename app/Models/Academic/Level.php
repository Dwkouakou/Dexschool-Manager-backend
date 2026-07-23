<?php

namespace App\Models\Academic;

use App\Models\Academic\Classe;
use App\Models\Academic\Cycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Level extends Model
{
    use HasFactory;

    protected $fillable = [
        'cycle_id',
        'name',
        'code',
        'order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // protected static function booted(): void
    // {
    //     static::addGlobalScope('establishment', function (Builder $builder) {
    //         $user = Auth::user();
    //         if ($user && !empty($user->establishment_id)) {
    //             $builder->whereHas('cycle', function ($q) use ($user) {
    //                 $q->where('cycles.establishment_id', $user->establishment_id);
    //             });
    //         }
    //     });
    // }

    protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('cycle');
            }
        });
    }

    public function cycle()
    {
        return $this->belongsTo(Cycle::class);
    }

    public function classes()
    {
        return $this->hasMany(Classe::class);
    }
}