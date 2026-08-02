<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */

trait BelongsToEstablishment
{
    protected static function bootBelongsToEstablishment(): void
    {
        static::addGlobalScope('establishment', function (Builder $builder) {
            $user = Auth::user();

            if ($user && isset($user->establishment_id) && $user->establishment_id) {
                $activeEstablishmentId = $user->viewing_establishment_id ?? $user->establishment_id;

                $builder->where(
                    $builder->getModel()->getTable() . '.establishment_id',
                    $activeEstablishmentId
                );
            }
        });

        static::creating(function ($model) {
            $user = Auth::user();

            if (empty($model->establishment_id) && $user && isset($user->establishment_id)) {
                $model->establishment_id = $user->viewing_establishment_id ?? $user->establishment_id;
            }
        });
    }

    public static function withoutEstablishmentScope()
    {
        return static::withoutGlobalScope('establishment');
    }

    public function establishment()
    {
        return $this->belongsTo(\App\Models\Establishment::class);
    }
}