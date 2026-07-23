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
        // FILTRE GLOBAL : chaque requête est scopée à l'établissement du user connecté.
        // Un SuperAdmin (pas d'establishment_id) ne déclenche aucun filtre.
        static::addGlobalScope('establishment', function (Builder $builder) {
            $user = Auth::user();

            if ($user && isset($user->establishment_id) && $user->establishment_id) {
                $builder->where(
                    $builder->getModel()->getTable() . '.establishment_id',
                    $user->establishment_id
                );
            }
        });

        // REMPLISSAGE AUTO : à la création, on injecte l'establishment_id si absent.
        static::creating(function ($model) {
            $user = Auth::user();

            if (empty($model->establishment_id) && $user && isset($user->establishment_id)) {
                $model->establishment_id = $user->establishment_id;
            }
        });
    }

    /**
     * Contourne ponctuellement le filtre (rapports SuperAdmin, etc.)
     * Usage : Student::withoutEstablishmentScope()->get();
     */
    public static function withoutEstablishmentScope()
    {
        return static::withoutGlobalScope('establishment');
    }

    public function establishment()
    {
        return $this->belongsTo(\App\Models\Establishment::class);
    }
}