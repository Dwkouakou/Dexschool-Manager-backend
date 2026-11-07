<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Academic\Classe;
use App\Models\Academic\ClassroomSubjectTeacher;
use App\Models\Academic\Period;
use App\Models\Academic\Subject;
use App\Models\Establishment;
use App\Models\Personel\Employee;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;

/**
 * Données du tableau de bord PERSONNEL (comptes qui ne sont ni administrateur ni « tous droits »).
 * GET /api/me/dashboard   (authentifié, aucune permission particulière requise)
 */
class DashboardProfileController extends Controller
{
    public function show()
    {
        $user = Auth::user();

        // Accès complet = rôle admin, ou toutes les permissions existantes
        $isAdmin = $user->roles()->where('slug', 'admin')->exists();
        $hasAll  = $user->getAllPermissions()->count() >= Permission::count();
        $full    = $isAdmin || $hasAll;

        if ($full) {
            return response()->json(['full_access' => true], 200);
        }

        $establishment = Establishment::find(current_establishment_id());
        $year          = AcademicYears::find(current_viewing_year_id());
        $period        = Period::where('is_active', true)->first();
        $employee      = Employee::with('position')->where('user_id', $user->id)->first();

        // Attributions (classes + matières) si la personne enseigne
        $classes = [];
        if ($employee) {
            $atts     = ClassroomSubjectTeacher::where('employee_id', $employee->id)->get();
            $classMap = Classe::whereIn('id', $atts->pluck('classe_id'))->get()->keyBy('id');
            $subjMap  = Subject::whereIn('id', $atts->pluck('subject_id'))->get()->keyBy('id');

            $classes = $atts->groupBy('classe_id')->map(function ($rows, $classeId) use ($classMap, $subjMap) {
                $c = $classMap->get($classeId);
                if (!$c) return null;
                return [
                    'id'       => $c->id,
                    'name'     => $c->name,
                    'subjects' => $rows->map(fn ($r) => $subjMap->get($r->subject_id)?->name)->filter()->values(),
                ];
            })->filter()->sortBy('name')->values();
        }

        return response()->json([
            'full_access'   => false,
            'name'          => $user->name,
            'roles'         => $user->roles->pluck('name')->values(),
            'establishment' => $establishment?->name,
            'academic_year' => $year?->name,
            'period'        => $period ? ['name' => $period->name, 'code' => $period->code] : null,
            'employee'      => $employee ? [
                'position'  => $employee->position?->name,
                'specialty' => $employee->specialty,
            ] : null,
            'classes'       => $classes,
        ], 200);
    }
}