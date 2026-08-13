<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Establishment;
use App\Models\officeAdministration\EnrollmentFinancial;
use App\Services\EstablishmentPositionService;
use App\Services\EstablishmentRoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class AffiliatedEstablishmentController extends Controller
{
    /**
     * Formate un établissement pour l'affichage dans le bandeau d'onglets.
     */
    private function formatForTabs(Establishment $establishment, $user): array
    {
        return [
            'id'       => $establishment->id,
            'name'     => $establishment->name,
            'code'     => $establishment->code,
            'is_home'  => $establishment->id === $user->establishment_id,
            'is_root'  => is_null($establishment->parent_establishment_id) && $establishment->child_quota > 0,
        ];
    }

    /**
     * Vérifie si l'utilisateur connecté a un rôle ACTIF réel dans
     * l'établissement donné (peu importe si c'est son établissement
     * d'origine ou non).
     */
    private function hasActiveRoleIn($user, int $establishmentId): bool
    {
        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.establishment_id', $establishmentId)
            ->exists();
    }

    /**
     * Liste tous les établissements du groupe scolaire accessibles à
     * l'utilisateur connecté (son établissement d'origine + tout
     * établissement du même groupe où il a un rôle actif réel).
     *
     * Si l'établissement de l'utilisateur ne fait partie d'aucun groupe
     * (cas normal, immense majorité des clients), renvoie juste lui-même
     * avec "is_group": false — le frontend n'affiche alors aucun onglet.
     *
     * GET /me/establishment-group
     */
    public function myGroup(Request $request)
    {
        $user = $request->user();
        $home = Establishment::findOrFail($user->establishment_id);

        $isGroup = $home->parent_establishment_id !== null || $home->child_quota > 0;

        if (!$isGroup) {
            return response()->json([
                'status'                    => 'success',
                'is_group'                  => false,
                'establishments'            => [$this->formatForTabs($home, $user)],
                'current_establishment_id'  => $user->viewing_establishment_id ?? $user->establishment_id,
            ]);
        }

        // Racine du groupe (le parent) — si "home" EST déjà la racine, c'est lui-même
        $rootId = $home->parent_establishment_id ?? $home->id;
        $root   = Establishment::find($rootId);

        // Tous les établissements du groupe : la racine + tous ses enfants
        $groupEstablishments = Establishment::where('id', $rootId)
            ->orWhere('parent_establishment_id', $rootId)
            ->get();

        // Filtre : uniquement ceux où l'utilisateur a un rôle actif RÉEL
        $accessibleIds = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->whereIn('roles.establishment_id', $groupEstablishments->pluck('id'))
            ->pluck('roles.establishment_id')
            ->unique();

        $accessible = $groupEstablishments->whereIn('id', $accessibleIds)->values();

        // Sécurité : l'établissement d'origine reste toujours listé, même
        // dans un cas limite où aucun rôle n'y serait formellement trouvé.
        if (!$accessible->contains('id', $home->id)) {
            $accessible->push($home);
        }

        return response()->json([
            'status'                    => 'success',
            'is_group'                  => true,
            'root_establishment_id'     => $root?->id,
            'establishments'            => $accessible->map(fn($e) => $this->formatForTabs($e, $user))->values(),
            'current_establishment_id'  => $user->viewing_establishment_id ?? $user->establishment_id,
            // Infos de quota — utile pour l'écran "Établissements affiliés",
            // uniquement pertinent si l'utilisateur CONSULTE ACTUELLEMENT
            // l'établissement racine (celui qui porte le quota). ─── CORRECTIF :
            // comparé à current_establishment_id() (établissement réellement
            // affiché après un switch d'onglet), pas à $home->id (l'origine du
            // compte, qui ne change jamais) — sinon un Admin dont le compte
            // est né sur la racine voyait le bouton "Établissements affiliés"
            // rester actif même en consultant un établissement enfant.
            'quota' => $root && $root->id === current_establishment_id() ? [
                'max'       => $root->child_quota,
                'used'      => Establishment::where('parent_establishment_id', $root->id)->count(),
                'remaining' => max(0, $root->child_quota - Establishment::where('parent_establishment_id', $root->id)->count()),
            ] : null,
        ]);
    }

    /**
     * Bascule le contexte de travail vers un autre établissement du groupe.
     * Vérifie que l'utilisateur y a bien un accès légitime AVANT de faire
     * quoi que ce soit — jamais de confiance aveugle sur l'ID fourni.
     *
     * POST /me/switch-establishment/{id}
     */
    public function switchTo(Request $request, string $id)
    {
        $user   = $request->user();
        $target = Establishment::find($id);

        if (!$target) {
            return response()->json(['status' => 'error', 'message' => "Établissement introuvable."], 404);
        }

        if (!$target->is_active) {
            return response()->json(['status' => 'error', 'message' => "Cet établissement est désactivé."], 403);
        }

        // Vérifie que target appartient bien au MÊME groupe que l'établissement d'origine
        $home         = Establishment::findOrFail($user->establishment_id);
        $homeRootId   = $home->parent_establishment_id ?? $home->id;
        $targetRootId = $target->parent_establishment_id ?? $target->id;

        if ($homeRootId !== $targetRootId) {
            return response()->json([
                'status'  => 'error',
                'message' => "Vous n'avez pas accès à cet établissement."
            ], 403);
        }

        // Vérifie un rôle actif réel sur la cible (sauf si c'est déjà
        // son établissement d'origine, toujours implicitement autorisé)
        if ($target->id !== $user->establishment_id && !$this->hasActiveRoleIn($user, $target->id)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Vous n'avez aucun rôle actif dans cet établissement. Contactez l'administrateur."
            ], 403);
        }

        // ── Bascule le contexte ──
        $user->viewing_establishment_id = $target->id;

        // Année active DE CET établissement précis (peut différer par cycle)
        $activeYear = AcademicYears::withoutGlobalScopes()
            ->where('establishment_id', $target->id)
            ->where('is_active', true)
            ->first();

        $user->viewing_year_id = $activeYear?->id;
        $user->save();

        // Rôle de l'utilisateur DANS cet établissement précis (peut différer du rôle d'origine)
        $roleInTarget = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', \App\Models\User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where('roles.establishment_id', $target->id)
            ->value('roles.name');

        // Même pattern que loginUser() : une seule session active à la fois
        $user->tokens()->delete();
        $newToken = $user->createToken('main_token', ['*'])->plainTextToken;

        return response()->json([
            'status'        => 'success',
            'message'       => "Vous gérez maintenant {$target->name}.",
            'token'         => $newToken,
            'user'          => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role'  => $roleInTarget,
            ],
            'establishment' => [
                'id'   => $target->id,
                'name' => $target->name,
                'code' => $target->code,
            ],
            'active_year'   => $activeYear,
        ]);
    }

    /**
     * L'Admin crée lui-même un établissement affilié (enfant), depuis son
     * propre tableau de bord — sans repasser par DexSchool, dans la limite
     * de son quota. RÉUTILISE le même compte admin (pas de duplication) et
     * crée automatiquement une année scolaire pour que l'établissement
     * soit immédiatement utilisable.
     *
     * POST /me/affiliated-establishments
     */
    public function createChildEstablishment(Request $request)
    {
        $user = $request->user();

        $isAdmin = $user->roles()->where('slug', 'admin')->exists();
        if (!$isAdmin) {
            return response()->json([
                'status'  => 'error',
                'message' => "Seul l'administrateur peut créer un établissement affilié."
            ], 403);
        }

        // ─── CORRECTIF : current_establishment_id() (établissement
        // réellement consulté après switch d'onglet) au lieu de
        // $user->establishment_id (origine du compte, qui ne change jamais).
        // Sans ça, un Admin dont le compte est né sur la racine pouvait
        // "créer un établissement affilié" en étant visuellement sur un
        // enfant — la vérification "un enfant ne peut pas créer" ne se
        // déclenchait jamais, et le nouvel établissement se retrouvait
        // rattaché à la racine au lieu d'être bloqué comme il se doit.
        $home = Establishment::findOrFail(current_establishment_id());

        // Un établissement déjà enfant ne peut pas lui-même créer d'affiliés
        if ($home->parent_establishment_id) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un établissement affilié ne peut pas créer d'autres établissements affiliés."
            ], 403);
        }

        if ($home->child_quota <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => "Votre établissement n'est pas autorisé à créer des établissements affiliés. Contactez le support DexSchool."
            ], 403);
        }

        $currentChildrenCount = Establishment::where('parent_establishment_id', $home->id)->count();
        if ($currentChildrenCount >= $home->child_quota) {
            return response()->json([
                'status'  => 'error',
                'message' => "Quota atteint : vous avez déjà créé {$currentChildrenCount}/{$home->child_quota} établissement(s) affilié(s)."
            ], 422);
        }

        $request->validate([
            'name'           => 'required|string|max:255',
            'code'           => ['required', 'string', 'max:50', Rule::unique('establishments', 'code')],
            'director_title' => 'nullable|string|max:100',
            'year_name'      => 'nullable|string|max:255',
            'year_start'     => 'nullable|date',
            'year_end'       => 'nullable|date|after:year_start',
        ], [
            'code.unique' => "Ce code établissement est déjà utilisé.",
        ]);

        try {
            $result = DB::transaction(function () use ($request, $home, $user) {

                // ─── Création directe, sans dépendre du $fillable ───
                $child = new Establishment([
                    'name'      => $request->name,
                    'code'      => strtoupper($request->code),
                    'is_active' => true,
                ]);
                $child->parent_establishment_id = $home->id;
                $child->child_quota             = 0; // un enfant ne peut pas avoir ses propres enfants
                $child->director_title          = $request->director_title ?: 'Directeur';
                $child->save();

                // Rôles + postes par défaut, même mécanisme que côté SuperAdmin
                app(EstablishmentRoleService::class)->createDefaultRolesFor($child);
                app(EstablishmentPositionService::class)->createDefaultPositions($child);

                // ── Réutilise le MÊME compte admin — jamais de duplication ──
                $adminRole = Role::where('establishment_id', $child->id)->where('slug', 'admin')->first();
                if ($adminRole) {
                    $user->assignRole($adminRole);
                }

                // ── Année scolaire automatique, calquée sur celle du parent
                // si aucune n'est précisée — sinon l'établissement enfant
                // serait inutilisable dès sa création (aucune connexion
                // standard possible sans année active). ──
                $parentActiveYear = AcademicYears::withoutGlobalScopes()
                    ->where('establishment_id', $home->id)
                    ->where('is_active', true)
                    ->first();

                $year = AcademicYears::create([
                    'establishment_id' => $child->id,
                    'name'             => $request->year_name ?: ($parentActiveYear->name ?? (date('Y') . '-' . (date('Y') + 1))),
                    'start_date'       => $request->year_start ?: ($parentActiveYear->start_date ?? now()->startOfYear()->toDateString()),
                    'end_date'         => $request->year_end ?: ($parentActiveYear->end_date ?? now()->endOfYear()->toDateString()),
                    'is_active'        => true,
                    'is_archived'      => false,
                ]);

                return compact('child', 'year');
            });

            return response()->json([
                'status'        => 'success',
                'message'       => "Établissement affilié \"{$result['child']->name}\" créé avec succès. Vous pouvez y basculer via les onglets.",
                'establishment' => $result['child'],
                'year'          => $result['year'],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Erreur createChildEstablishment : ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la création.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Bilan financier CONSOLIDÉ du groupe scolaire — additionne les
     * indicateurs (dû, encaissé, reste à recouvrer, effectifs) de tous les
     * établissements affiliés (parent + enfants), et fournit AUSSI le
     * détail par établissement pour les onglets côté frontend.
     *
     * Réservé à l'établissement RACINE — un établissement enfant
     * ne peut pas consulter cette vue globale.
     *
     * Accessible à tout utilisateur ayant la permission "financial_reports.view"
     * (Admin, Comptable...) — pas réservé au seul Admin, contrairement à la
     * création d'établissements affiliés.
     *
     * GET /me/group-financial-summary
     */
    public function groupFinancialSummary(Request $request)
    {
        $user = $request->user();

        if (!$user->can('financial_reports.view')) {
            return response()->json([
                'status'  => 'error',
                'message' => "Vous n'avez pas la permission de consulter les rapports financiers."
            ], 403);
        }

        // ─── CORRECTIF : current_establishment_id() au lieu de
        // $user->establishment_id (origine du compte) — sinon un Admin dont
        // le compte est né sur la racine gardait un accès direct à cette API
        // (via appel direct de l'URL) même en consultant un établissement
        // enfant, malgré le lien masqué côté Sidebar.
        $home = Establishment::findOrFail(current_establishment_id());

        // Doit être l'établissement RACINE (pas un enfant) pour voir la
        // vue consolidée — un enfant n'a de toute façon jamais de quota.
        if ($home->parent_establishment_id) {
            return response()->json([
                'status'  => 'error',
                'message' => "Cette vue n'est disponible que depuis l'établissement principal du groupe."
            ], 403);
        }

        // Tous les établissements du groupe (la racine elle-même + ses enfants)
        $groupEstablishments = Establishment::where('id', $home->id)
            ->orWhere('parent_establishment_id', $home->id)
            ->get();

        $perEstablishment = [];
        $grandTotalDue      = 0;
        $grandTotalEncaisse = 0;
        $grandTotalEleves   = 0;

        foreach ($groupEstablishments as $est) {
            // Année active DE CET établissement précis (peut différer par cycle)
            $activeYear = AcademicYears::withoutGlobalScopes()
                ->where('establishment_id', $est->id)
                ->where('is_active', true)
                ->first();

            if (!$activeYear) {
                // Établissement sans année active configurée — on l'affiche
                // quand même dans la liste, avec des totaux à zéro, plutôt
                // que de faire planter tout le bilan du groupe pour ça.
                $perEstablishment[] = [
                    'establishment_id'   => $est->id,
                    'establishment_name' => $est->name,
                    'establishment_code' => $est->code,
                    'academic_year'      => null,
                    'total_due'          => 0,
                    'total_encaisse'     => 0,
                    'reste_a_recouvrer'  => 0,
                    'total_eleves'       => 0,
                    'warning'            => "Aucune année scolaire active configurée.",
                ];
                continue;
            }

            $financialsQuery = fn() => EnrollmentFinancial::withoutGlobalScopes()
                ->whereHas('enrollment', function ($q) use ($activeYear, $est) {
                    $q->withoutGlobalScopes()
                      ->where('academic_year_id', $activeYear->id)
                      ->whereHas('student', fn($s) => $s->withoutGlobalScopes()->where('establishment_id', $est->id));
                });

            $totalDue      = (int) (clone $financialsQuery())->sum('total_due');
            $totalEncaisse = (int) (clone $financialsQuery())->sum('initial_payment');
            $totalEleves   = (int) (clone $financialsQuery())->count();
            $resteA_Recouvrer = max(0, $totalDue - $totalEncaisse);

            $perEstablishment[] = [
                'establishment_id'   => $est->id,
                'establishment_name' => $est->name,
                'establishment_code' => $est->code,
                'academic_year'      => $activeYear->name,
                'total_due'          => $totalDue,
                'total_encaisse'     => $totalEncaisse,
                'reste_a_recouvrer'  => $resteA_Recouvrer,
                'total_eleves'       => $totalEleves,
            ];

            $grandTotalDue      += $totalDue;
            $grandTotalEncaisse += $totalEncaisse;
            $grandTotalEleves   += $totalEleves;
        }

        $grandTotalReste = max(0, $grandTotalDue - $grandTotalEncaisse);
        $tauxRecouvrement = $grandTotalDue > 0 ? round(($grandTotalEncaisse / $grandTotalDue) * 100, 1) : 0;

        return response()->json([
            'status' => 'success',
            'group'  => [
                'total_due'          => $grandTotalDue,
                'total_encaisse'     => $grandTotalEncaisse,
                'reste_a_recouvrer'  => $grandTotalReste,
                'taux_recouvrement'  => (float) $tauxRecouvrement,
                'total_eleves'       => $grandTotalEleves,
                'establishments_count' => $groupEstablishments->count(),
            ],
            'per_establishment' => $perEstablishment,
        ]);
    }
}