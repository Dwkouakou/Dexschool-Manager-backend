<?php

namespace App\Models\Transport;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Student;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Transport\TransportRoute;
use Illuminate\Database\Eloquent\Model;

class TransportSubscription extends Model
{
    //
    use BelongsToEstablishment, BelongsToActiveYear; 
    
     protected $fillable = ['student_id', 'academic_year_id', 'route_id', 'start_date', 'end_date', 'total_amount', 'amount_paid', 'status', 'payment_status', 'notes', 'establishment_id',];
    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'total_amount' => 'integer', 'amount_paid' => 'integer'];

    public function student() { return $this->belongsTo(Student::class); }
    public function route() { return $this->belongsTo(TransportRoute::class, 'route_id'); }
    public function academicYear() { return $this->belongsTo(AcademicYears::class, 'academic_year_id'); }
}
