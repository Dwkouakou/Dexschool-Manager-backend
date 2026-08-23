<?php

namespace App\Models\Canteen;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Student;
use App\Models\Canteen\CanteenAttendance;
use App\Models\Canteen\CanteenPayment;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use Illuminate\Database\Eloquent\Model;

class CanteenSubscription extends Model
{
    //
    use BelongsToEstablishment, BelongsToActiveYear;
     protected $fillable = [
        'student_id', 'academic_year_id', 'meal_type_id', 
        'start_date', 'end_date', 'current_period_start', 'total_amount', 'amount_paid', 'status', 'payment_status', 'notes',
        'establishment_id',
    ];

    protected $casts = [
        'start_date' => 'date', 'end_date' => 'date', 'current_period_start' => 'date',
        'total_amount' => 'integer', 'amount_paid' => 'integer'
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function academicYear() { return $this->belongsTo(AcademicYears::class); }
    public function mealType() { return $this->belongsTo(MealType::class); }
    public function attendances() { return $this->hasMany(CanteenAttendance::class, 'subscription_id'); }

    // Historique des versements et renouvellements liés à cet abonnement
    public function payments() { return $this->hasMany(CanteenPayment::class, 'canteen_subscription_id'); }

}