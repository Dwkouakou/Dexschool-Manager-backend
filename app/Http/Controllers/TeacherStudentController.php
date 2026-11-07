<?php

namespace App\Http\Controllers;

use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Student;
use App\Models\Personel\Employee;
use Illuminate\Support\Facades\Auth;

/**
 * Liste RESTREINTE des élèves pour un enseignant : uniquement les élèves de SES classes,
 * sans coordonnées, sans tuteur, sans aucune donnée financière.
 * GET /api/teacher/students
 */
class TeacherStudentController extends Controller
{
    public function index()
    {
        $employee = Employee::with('position')->where('user_id', Auth::id())->first();

        $isTeacher = $employee && ($employee->position?->slug === 'enseignant'
            || ClassroomSubjectTeacher::where('employee_id', $employee->id)->exists());

        if (!$isTeacher) {
            return response()->json(['is_teacher' => false], 200);
        }

        $classeIds = ClassroomSubjectTeacher::where('employee_id', $employee->id)->pluck('classe_id')->unique();
        $classes   = Classe::whereIn('id', $classeIds)->orderBy('name')->get(['id', 'name']);

        $students = Student::whereIn('class_id', $classes->pluck('id'))
            ->where('is_active', true)
            ->orderBy('last_name')->orderBy('first_name')
            ->get(['id', 'class_id', 'matricule', 'national_matricule', 'last_name', 'first_name', 'gender', 'birth_date', 'photo']);

        return response()->json([
            'is_teacher' => true,
            'classes'    => $classes,
            'students'   => $students->map(fn ($s) => [
                'id'         => $s->id,
                'class_id'   => $s->class_id,
                'matricule'  => $s->national_matricule ?: $s->matricule,
                'last_name'  => $s->last_name,
                'first_name' => $s->first_name,
                'gender'     => $s->gender,
                'birth_date' => $s->birth_date,
                'photo'      => $s->photo,
            ])->values(),
        ], 200);
    }
}