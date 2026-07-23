<?php

namespace App\Models\Personel;

use App\Models\Academic\AbsenceSheet;
use App\Models\Academic\Evaluation;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Personel\EmployeeContrat;
use App\Models\Personel\Payroll;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    //
     use HasFactory, SoftDeletes, BelongsToEstablishment;

    protected $fillable = [
        'user_id', 'position_id', 'matricule', 'last_name', 'first_name', 
        'gender', 'phone', 'email', 'address', 'photo', 'specialty', 'hire_date', 'status'
    ];

    protected $casts = [
        'hire_date' => 'date',
    ];

    public function position() {
        return $this->belongsTo(Positions::class);
    }

    public function contracts() {
        return $this->hasMany(EmployeeContrat::class);
    }

    public function activeContract() {
        return $this->hasOne(EmployeeContrat::class)->where('status', 'active');
    }

    public function payrolls() {
        return $this->hasMany(Payroll::class);
    }

    // Les évaluations créées par cet enseignant
    public function evaluations()
    {
        return $this->hasMany(Evaluation::class, 'employee_id');
    }

    // Les appels/feuilles d'absences effectués par cet enseignant (Module 3)
    public function absenceSheets()
    {
        return $this->hasMany(AbsenceSheet::class, 'employee_id');
    }

}
