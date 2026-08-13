<?php

namespace App\Http\Controllers;

use App\Models\EstablishmentActivityLog;
use Illuminate\Http\Request;

class EstablishmentActivityLogController extends Controller
{
    /**
     * GET /activity-logs?search=&family=&page=&per_page=
     *
     * Journal d'activité de l'établissement ACTUELLEMENT CONSULTÉ
     * (current_establishment_id() — fonctionne identiquement pour un
     * établissement simple ou un enfant de groupe scolaire consulté via
     * switch d'onglet).
     *
     * Contrat de réponse (même forme que le journal SuperAdmin) :
     * { data: [{ id, actor_name, actor_role, action, description,
     *            subject_type, subject_id, created_at }], total, last_page }
     */
    public function logs(Request $request)
    {
        // ─── Vérification de permission EN INTERNE au contrôleur, comme
        // partout ailleurs dans l'app (ex: groupFinancialSummary()) — cette
        // application n'a pas d'alias middleware "permission" enregistré,
        // donc ->middleware('permission:...') sur la route casse tout avec
        // une erreur 500 ("Target class [permission] does not exist").
        if (!$request->user()->can('activity_logs.view')) {
            return response()->json([
                'status'  => 'error',
                'message' => "Vous n'avez pas la permission de consulter le journal d'activité.",
            ], 403);
        }

        $query = EstablishmentActivityLog::where('establishment_id', current_establishment_id());

        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'LIKE', "%{$search}%")
                  ->orWhere('actor_name', 'LIKE', "%{$search}%");
            });
        }

        // Familles alignées sur les modules de l'application — chaque
        // contrôleur qui appelle EstablishmentActivityLog::record() doit
        // préfixer son action avec le nom du module concerné (student.,
        // enrollment., payment., employee., canteen., transport., library.,
        // user., role., settings.) pour rester filtrable ici.
        if ($family = $request->family) {
            $query->where('action', 'LIKE', "{$family}.%");
        }

        $perPage = (int) ($request->per_page ?? 20);
        $paginator = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data'         => $paginator->items(),
            'total'        => $paginator->total(),
            'last_page'    => $paginator->lastPage(),
            'current_page' => $paginator->currentPage(),
        ]);
    }
}