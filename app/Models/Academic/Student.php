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
    ];

    protected $casts = [
        'birth_date'       => 'date:Y-m-d',
        'is_active'        => 'boolean',
        'class_id'         => 'integer',
        'academic_year_id' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELATIONS (BelongsTo)
    |--------------------------------------------------------------------------
    */

    /**
     * Un élève appartient à une classe.
     */
    public function classe()
    {
        return $this->belongsTo(Classe::class, 'class_id');
    }
     
    /**
     * Un élève est inscrit pour une année académique spécifique.
     */
    public function academicYear()
    {
        return $this->belongsTo(AcademicYears::class, 'academic_year_id');
    }

    /**
     * Utilisateur ayant créé le dossier de l'élève.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | RELATIONS INDIRECTES (Via d'autres modèles)
    |--------------------------------------------------------------------------
    */

    /**
     * Accéder au niveau (Level) de l'élève en passant par sa classe.
     * Permet de faire : $student->level
     */
    public function level()
    {
        // Un élève a un niveau à travers (hasOneThrough) sa classe
        return $this->hasOneThrough(
            Level::class, 
            Classe::class, 
            'id',         // Clé locale sur la table 'classes' (id de la classe)
            'id',         // Clé locale sur la table 'levels' (id du level)
            'class_id',   // Clé étrangère sur la table 'students'
            'level_id'    // Clé étrangère sur la table 'classes'
        );
    }



    /**
     * Un élève possède plusieurs parents/tuteurs enregistrés.
     */
    public function parents()
    {
        return $this->hasMany(StudentParent::class, 'student_id');
    }

    /**
     * Relations directes filtrées (Très utile pour vos futurs formulaires)
     */
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


    // RELATION : Historique complet des inscriptions de l'élève
    public function enrollments()
    {
        return $this->hasMany(Enrollment::class, 'student_id');
    }

    // RELATION : Récupérer la dernière inscription active de l'élève
    public function latestEnrollment()
    {
        return $this->hasOne(Enrollment::class, 'student_id')
        ->withoutGlobalScope('viewingYear')
        ->latestOfMany();
    }

    public function canteenSubscriptions() {
        return $this->hasMany(CanteenSubscription::class, 'student_id');
    }

    public function transportSubscriptions() {
        return $this->hasMany(TransportSubscription::class);
    }

    // Toutes les notes de l'élève
    public function grades()
    {
        return $this->hasMany(Grade::class, 'student_id');
    }

    // Tout l'historique des présences/absences de l'élève (Module 3)
    public function attendanceRecords()
    {
        return $this->hasMany(AttendanceRecord::class, 'student_id');
    }

    // Récupérer toutes les moyennes par matière de l'élève
    public function subjectAverages()
    {
        return $this->hasMany(SubjectAverage::class, 'student_id');
    }

    // Récupérer les moyennes générales (bulletins) de l'élève
    public function periodAverages()
    {
        return $this->hasMany(PeriodAverage::class, 'student_id');
    }

    public function academicRecords()
    {
        return $this->hasMany(StudentAcademicRecord::class);
    }

    

}