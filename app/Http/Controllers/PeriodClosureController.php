<?php

namespace App\Http\Controllers;

use App\Models\Academic\Period;
use Illuminate\Support\Facades\Auth;

/**
 * Clôture des périodes (module Notes) :
 *  - « Bloquer prof » : les enseignants ne peuvent plus saisir/modifier les notes de la période
 *  - « Clôturer »     : plus personne (administrateur compris) ne peut saisir/modifier les notes de la période
 */
class PeriodClosureController extends Controller
{
    private function row(Period $p): array
    {
        return [
            'id'                 => $p->id,
            'name'               => $p->name,
            'code'               => $p->code,
            'is_active'          => (bool) $p->is_active,
            'teachers_locked'    => (bool) $p->teachers_locked,
            'teachers_locked_at' => $p->teachers_locked_at,
            'is_closed'          => (bool) $p->is_closed,
            'closed_at'          => $p->closed_at,
        ];
    }

    /** GET /grades/period-closures */
    public function index()
    {
        $periods = Period::where('academic_year_id', current_viewing_year_id())->orderBy('id')->get();
        return response()->json($periods->map(fn ($p) => $this->row($p))->values(), 200);
    }

    /** POST /grades/period-closures/{id}/teachers-lock  (bascule) */
    public function toggleTeachersLock(string $id)
    {
        assert_writable_year();
        $p = Period::find($id);
        if (!$p) return response()->json(['message' => 'Période introuvable.'], 404);

        $lock = !$p->teachers_locked;
        $p->forceFill(['teachers_locked' => $lock, 'teachers_locked_at' => $lock ? now() : null])->save();

        return response()->json([
            'message' => $lock ? "Saisie des notes bloquée pour les professeurs ({$p->name})." : "Saisie des notes rouverte aux professeurs ({$p->name}).",
            'data'    => $this->row($p->fresh()),
        ], 200);
    }

    /** POST /grades/period-closures/{id}/close  (bascule clôturer / déverrouiller) */
    public function toggleClose(string $id)
    {
        assert_writable_year();
        $p = Period::find($id);
        if (!$p) return response()->json(['message' => 'Période introuvable.'], 404);

        $close = !$p->is_closed;
        $p->forceFill([
            'is_closed' => $close,
            'closed_at' => $close ? now() : null,
            'closed_by' => $close ? Auth::id() : null,
        ])->save();

        return response()->json([
            'message' => $close ? "{$p->name} clôturé : plus aucune saisie de notes n'est possible." : "{$p->name} déverrouillé : la saisie est de nouveau possible.",
            'data'    => $this->row($p->fresh()),
        ], 200);
    }
}