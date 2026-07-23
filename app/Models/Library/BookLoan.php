<?php

namespace App\Models\Library;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Student;
use App\Models\Concerns\BelongsToActiveYear;
use App\Models\Concerns\BelongsToEstablishment;
use App\Models\Library\BookCopy;
use App\Models\Personel\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BookLoan extends Model
{
    //

    use BelongsToEstablishment , BelongsToActiveYear; 
    
     protected $fillable = ['book_copy_id', 'academic_year_id', 'student_id', 'employee_id', 'loan_date', 'expected_return_date', 'returned_at', 'status', 'notes', 'created_by', 'establishment_id',];
    protected $casts = ['loan_date' => 'date', 'expected_return_date' => 'date', 'returned_at' => 'date'];

    public function copy() { return $this->belongsTo(BookCopy::class, 'book_copy_id'); }
    public function academicYear() { return $this->belongsTo(AcademicYears::class, 'academic_year_id'); }
    public function student() { return $this->belongsTo(Student::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
