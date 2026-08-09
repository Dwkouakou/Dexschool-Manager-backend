<?php

namespace App\Models\Academic;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\AttendanceRecord;
use App\Models\Academic\Classe;
use App\Models\Academic\Grade;
use App\Models\Academic\PeriodAverage;
use App\Models\Academic\StudentDocument;
use App\Models\Academic\StudentParent;
use App\Models\Canteen\CanteenSubscription;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\officeAdministration\Enrollment;
use App\Models\Transport\TransportSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use HasFactory , SoftDeletes, BelongsToEstablishment;

    protected $table = 'students';

    protected $fillable = [
        'matricule',
        'last_name',
        'first_name',
        'gender',
        'birth_date',
        'birth_place',
        'phone',
        'email',
        'address',
        'class_id',
        'academic_year_id',
        'photo',
        'is_active',
        'created_by',
        'updated_by',
        'establishment_id',

        'nationality', 'national_matricule', 'provisional_matricule', 'origin_school',
        'is_transferred', 'is_enrolled', 'assignment_status',

        'religion', 'handicap',
    ];

    protected $casts = [
        'birth_date'       => 'date:Y-m-d',
        'is_active'        => 'boolean',
        'class_id'         => 'integer',
        'academic_year_id' => 'integer',
    ];

    public function classe()
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }

    public function academicYear()
    {
        return $this->belongsTo(AcademicYears::class, 'academic_year_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function level()
    {
        return $this->hasOneThrough(
            Level::class,
            Classe::class,
            'id',
            'id',
            'class_id',
            'level_id'
        );
    }

    public function parents()
    {
        return $this->hasMany(StudentParent::class, 'student_id');
    }

    public function father()
    {
        return $this->hasOne(StudentParent::class, 'student_id')->where('type', 'father');
    }

    public function mother()
    {
        return $this->hasOne(StudentParent::class, 'student_id')->where('type', 'mother');
    }

    public function guardian()
    {
        return $this->hasOne(StudentParent::class, 'student_id')->where('type', 'guardian');
    }

    public function documents()
    {
        return $this->hasMany(StudentDocument::class, 'student_id');
    }

    public function enrollments()
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    public function latestEnrollment()
    {
        // ─── CORRECTIF : une inscription annulée ne doit jamais être
        // considérée comme "la dernière inscription" de l'élève — sinon
        // tout calcul basé dessus (reliquat, résumé financier...) se
        // baserait à tort sur une tentative annulée plutôt que sur la
        // vraie dernière inscription active (ou aucune, si toutes ont
        // été annulées). ───
        return $this->hasOne(Enrollment::class, 'student_id')
        ->withoutGlobalScope('viewingYear')
        ->where('status', '!=', 'cancelled')
        ->latestOfMany();
    }

    public function canteenSubscriptions() {
        return $this->hasMany(CanteenSubscription::class, 'student_id');
    }

    public function transportSubscriptions() {
        return $this->hasMany(TransportSubscription::class);
    }

    public function grades()
    {
        return $this->hasMany(Grade::class, 'student_id');
    }

    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'student_id');
    }

    public function subjectAverages()
    {
        return $this->hasMany(SubjectAverage::class, 'student_id');
    }

    public function periodAverages()
    {
        return $this->hasMany(PeriodAverage::class, 'student_id');
    }

    public function academicRecords()
    {
        return $this->hasMany(StudentAcademicRecord::class);
    }

}