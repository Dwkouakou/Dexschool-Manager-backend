<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait BelongsToActiveYear
{
    protected static function bootBelongsToActiveYear(): void
    {
        static::addGlobalScope('viewingYear', function (Builder $builder) {
            $user = Auth::user();
            if (!$user || empty($user->establishment_id)) return;

            $yearId = current_viewing_year_id();
            if ($yearId) {
                $builder->where($builder->getModel()->getTable() . '.academic_year_id', $yearId);
            }
        });
    }

    public static function withoutYearScope()
    {
        return static::withoutGlobalScope('viewingYear');
    }
}