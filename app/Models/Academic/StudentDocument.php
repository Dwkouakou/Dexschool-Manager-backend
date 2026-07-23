<?php

namespace App\Models\Academic;

use App\Models\Academic\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class StudentDocument extends Model
{
    //
      /**
     * Les attributs assignables en masse.
     */
    protected $fillable = [
        'student_id',
        'document_type',
        'title',
        'file_path',
        'file_name',
        'mime_type',
        'file_size',
        'description',
        'issue_date',
        'expiration_date',
        'is_required',
    ];

    /**
     * Les attributs à transtyper (Casting).
     */
    protected $casts = [
        'issue_date'       => 'date',
        'expiration_date'  => 'date',
        'is_required'      => 'boolean',
        'file_size'        => 'integer',
    ];

    /**
     * Relation inverse : Un document appartient à un seul élève.
     */
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

      protected static function booted(): void
    {
        static::addGlobalScope('scope', function (Builder $builder) {
            if (Auth::check()) {
                $builder->whereHas('student');
            }
        });
    }
}
