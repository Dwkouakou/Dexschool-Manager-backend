<?php

namespace App\Models\Canteen;

use App\Models\Canteen\CanteenSubscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CanteenAttendance extends Model
{
    //
     protected $fillable = ['subscription_id', 'attendance_date', 'present', 'notes'];
    protected $casts = ['attendance_date' => 'date', 'present' => 'boolean'];

    public function subscription() {
        return $this->belongsTo(CanteenSubscription::class, 'subscription_id');
    }

       protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('subscription');
            }
        });
    }
}
