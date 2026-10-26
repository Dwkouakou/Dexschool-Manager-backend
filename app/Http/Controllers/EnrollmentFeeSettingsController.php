<?php

namespace App\Http\Controllers;

use App\Models\Academic\Cycle;
use App\Models\Academic\Level;
use App\Models\Establishment;
use App\Models\EstablishmentLevelFee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EnrollmentFeeSettingsController extends Controller
{
    private const STATUSES = ['affecte', 'non_affecte'];

    /** IDs des établissements du groupe où l'utilisateur est légitime. */
    private function accessibleEstablishments($user)
    {
        $home   = Establishment::findOrFail($user->establishment_id);
        $rootId = $home->parent_establishment_id ?? $home->id;

        $group = Establishment::where('id', $rootId)
            ->orWhere('parent_establishment_id', $rootId)
            ->get();

        $withRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->whereIn('roles.establishment_id', $group->pluck('id'))
            ->pluck('roles.establishment_id')
            ->unique();

        return $group->filter(fn ($e) => $e->id === $user->establishment_id || $withRole->contains($e->id))->values();
    }

    /** Établissement ciblé : celui demandé s'il est accessible, sinon l'actif. */
    private function resolveTarget(Request $request, $accessible): int
    {
        $wanted = (int) ($request->input('establishment_id') ?: current_establishment_id());
        abort_unless($accessible->contains('id', $wanted), 403, "Accès refusé à cet établissement.");
        return $wanted;
    }

    /** Cycles d'un établissement (Level n'a pas d'establishment_id : il est scopé via son cycle). */
    private function cyclesOf(int $establishmentId)
    {
        return Cycle::withoutGlobalScopes()
            ->where('establishment_id', $establishmentId)
            ->get(['id', 'name'])
            ->keyBy('id');
    }

    private function levelsOf(int $establishmentId)
    {
        $cycles = $this->cyclesOf($establishmentId);

        return Level::withoutGlobalScopes()
            ->whereIn('cycle_id', $cycles->keys())
            ->orderBy('order')
            ->get()
            ->map(fn ($l) => [
                'id'         => $l->id,
                'name'       => $l->name,
                'cycle_id'   => $l->cycle_id,
                'cycle_name' => $cycles[$l->cycle_id]->name ?? null,
            ])->values();
    }

    /**
     * GET /api/settings/enrollment-fees?establishment_id=
     * Niveaux de l'établissement ciblé + grille déjà configurée.
     */
    public function index(Request $request)
    {
        $accessible = $this->accessibleEstablishments($request->user());
        $targetId   = $this->resolveTarget($request, $accessible);

        $fees = EstablishmentLevelFee::where('establishment_id', $targetId)
            ->get(['id', 'level_id', 'assignment_status', 'registration_fee', 'tuition_fee', 'updated_at']);

        return response()->json([
            'status'                  => 'success',
            'is_group'                => $accessible->count() > 1,
            'current_establishment_id'=> current_establishment_id(),
            'target_establishment_id' => $targetId,
            'establishments'          => $accessible->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'code' => $e->code])->values(),
            'levels'                  => $this->levelsOf($targetId),
            'fees'                    => $fees,
        ]);
    }

    /**
     * PUT /api/settings/enrollment-fees
     * Crée ou met à jour le tarif d'un (établissement, niveau, statut).
     */
    public function upsert(Request $request)
    {
        $accessible = $this->accessibleEstablishments($request->user());
        $targetId   = $this->resolveTarget($request, $accessible);

        $data = $request->validate([
            'level_id'          => ['required', 'integer'],
            'assignment_status' => ['required', Rule::in(self::STATUSES)],
            'registration_fee'  => ['required', 'integer', 'min:0', 'max:100000000'],
            'tuition_fee'       => ['nullable', 'integer', 'min:0', 'max:100000000'],
        ]);

        $levelOk = Level::withoutGlobalScopes()
            ->where('id', $data['level_id'])
            ->whereIn('cycle_id', $this->cyclesOf($targetId)->keys())
            ->exists();
        abort_unless($levelOk, 422, "Ce niveau n'appartient pas à l'établissement ciblé.");

        $tuition = $data['assignment_status'] === 'non_affecte' ? (int) ($data['tuition_fee'] ?? 0) : 0;

        $fee = EstablishmentLevelFee::updateOrCreate(
            [
                'establishment_id'  => $targetId,
                'level_id'          => $data['level_id'],
                'assignment_status' => $data['assignment_status'],
            ],
            [
                'registration_fee' => (int) $data['registration_fee'],
                'tuition_fee'      => $tuition,
                'updated_by'       => $request->user()->id,
            ]
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Tarif enregistré.',
            'fee'     => $fee,
        ]);
    }

    /** DELETE /api/settings/enrollment-fees/{id} */
    public function destroy(Request $request, string $id)
    {
        $accessible = $this->accessibleEstablishments($request->user());
        $fee = EstablishmentLevelFee::findOrFail($id);
        abort_unless($accessible->contains('id', $fee->establishment_id), 403, "Accès refusé.");
        $fee->delete();

        return response()->json(['status' => 'success', 'message' => 'Tarif supprimé.']);
    }

    /**
     * GET /api/enrollment-fees?level_id=&assignment_status=
     * Pour les étapes inscription / réinscription (établissement actif).
     * Avec les 2 paramètres → un tarif ; sans → toute la grille.
     */
    public function lookup(Request $request)
    {
        $estId = current_establishment_id();
        $query = EstablishmentLevelFee::where('establishment_id', $estId);

        // Mode pratique pour les formulaires : classe + élève → tarif résolu côté serveur.
        if ($request->filled('class_id') && $request->filled('student_id')) {
            $classe  = \App\Models\Academic\Classe::findOrFail($request->class_id);
            $student = \App\Models\Academic\Student::findOrFail($request->student_id);
            $status  = $student->assignment_status ?: 'non_affecte';

            $fee = $query->where('level_id', $classe->level_id)
                ->where('assignment_status', $status)->first();

            return response()->json([
                'status'            => 'success',
                'configured'        => (bool) $fee,
                'assignment_status' => $status,
                'registration_fee'  => (int) ($fee->registration_fee ?? 0),
                'tuition_fee'       => (int) ($fee->tuition_fee ?? 0),
            ]);
        }

        if ($request->filled('level_id') && $request->filled('assignment_status')) {
            $request->validate(['assignment_status' => [Rule::in(self::STATUSES)]]);
            $fee = $query->where('level_id', $request->level_id)
                ->where('assignment_status', $request->assignment_status)->first();

            return response()->json([
                'status'           => 'success',
                'configured'       => (bool) $fee,
                'registration_fee' => $fee->registration_fee ?? 0,
                'tuition_fee'      => $fee->tuition_fee ?? 0,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'fees'   => $query->get(['level_id', 'assignment_status', 'registration_fee', 'tuition_fee']),
        ]);
    }
}