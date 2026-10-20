<?php

namespace App\Models\officeAdministration;

use App\Models\Academic\Student;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Historique des corrections de la situation financière d'une inscription.
 * Chaque ligne conserve les montants avant/après, le motif, l'auteur et la
 * date. Jamais modifié ni supprimé (traçabilité comptable).
 */
class EnrollmentFinancialCorrection extends Model
{
    use BelongsToEstablishment;

    protected $fillable = [
        'establishment_id', 'enrollment_id', 'student_id', 'academic_year_id',
        'old_values', 'new_values', 'paid_at_time', 'reason',
        'corrected_by', 'corrected_at',
    ];

    protected $casts = [
        'old_values'   => 'array',
        'new_values'   => 'array',
        'corrected_at' => 'datetime',
    ];

    public function enrollment() { return $this->belongsTo(Enrollment::class, 'enrollment_id')->withTrashed(); }
    public function student()    { return $this->belongsTo(Student::class, 'student_id')->withTrashed(); }
    public function author()     { return $this->belongsTo(User::class, 'corrected_by'); }
}