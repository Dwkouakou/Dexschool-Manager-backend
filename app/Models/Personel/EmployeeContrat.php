<?php

namespace App\Models\Personel;

use App\Models\Personel\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EmployeeContrat extends Model
{
    // PAS de trait BelongsToEstablishment ici : "employee_contrats" est une
    // table ENFANT (Catégorie C) sans colonne establishment_id en base.
    // L'isolation multi-tenant passe entièrement par le scope ci-dessous,
    // qui vérifie l'appartenance via la relation "employee" (Employee, lui,
    // a bien establishment_id + le trait).
    protected $fillable = ['employee_id', 'contract_type', 'start_date', 'end_date', 'base_salary', 'status', 'notes'];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'base_salary' => 'integer',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('employee');
            }
        });
    }
}