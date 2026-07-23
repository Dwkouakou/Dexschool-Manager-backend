<?php

namespace App\Models\Personel;

use App\Models\Personel\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Payroll extends Model
{
    // PAS de trait BelongsToEstablishment ici : "payrolls" est une table
    // ENFANT (Catégorie C) sans colonne establishment_id en base.
    // L'isolation multi-tenant passe entièrement par le scope ci-dessous,
    // qui vérifie l'appartenance via la relation "employee" (Employee, lui,
    // a bien establishment_id + le trait).
    protected $fillable = [
        'employee_id', 'contract_id', 'payroll_number', 'salary_month',
        'base_salary', 'allowances', 'deductions', 'net_salary', 'payment_date', 'payment_method', 'status', 'created_by'
    ];

    protected $casts = [
        'payment_date' => 'date',
        'base_salary'  => 'integer',
        'allowances'   => 'integer',
        'deductions'   => 'integer',
        'net_salary'   => 'integer',
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