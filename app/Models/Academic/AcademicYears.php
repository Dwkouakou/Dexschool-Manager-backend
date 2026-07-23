<?php

namespace App\Models\Academic;

use App\Models\Canteen\CanteenSubscription;
use App\Models\Establishment;
use App\Models\Transport\TransportSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AcademicYears extends Model
{
    //
    use HasFactory;
    
      protected $fillable = [
        "establishment_id",
        'name',
        'start_date',
        'end_date',
        'is_active',
        'is_archived',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
    ];


    //  public function creator()
    // {
    //     return $this->belongsTo(User::class, 'created_by');
    // }

    public function establishment()
    {
        return $this->belongsTo(Establishment::class);
    }

    public function canteenSubscriptions() {
        return $this->hasMany(CanteenSubscription::class, 'academic_year_id');
    }

    public function TransportSubscriptions () {
        return $this->hasMany(TransportSubscription::class);
    }

    public function periods () {
        return $this->hasMany(Period::class, 'academic_year_id');
    }

}
