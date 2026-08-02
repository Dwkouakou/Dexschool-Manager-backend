<?php

namespace App\Http\Controllers;

use App\Models\Academic\AcademicYears;
use App\Models\Establishment;
use App\Models\SuperAdmin;
use App\Models\User;
use App\Services\EstablishmentRoleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class SuperAdminApiController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // AUTH
    // ─────────────────────────────────────────────────────────────────────────

    public function login(Request $request)
    {
        $request->validate([
            'login'    => 'required|string',
            'password' => 'required|string',
        ]);

        $superAdmin = SuperAdmin::where(function ($query) use ($request) {
            $query->where('email', $request->login)
                  ->orWhere('phone', $request->login);
        })->first();

        if ($superAdmin && Hash::check($request->password, $superAdmin->password)) {

            if (!$superAdmin->is_active) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Votre accès super-administrateur a été désactivé.'
                ], 403);
            }

            $superAdmin->tokens()->delete();
            $token = $superAdmin->createToken('superadmin_token', ['*'])->plainTextToken;

            return response()->json([
                'status'    => 'success',
                'user_type' => 'super_admin',
                'UserData'  => [
                    'id'    => $superAdmin->id,
                    'name'  => $superAdmin->name,
                    'email' => $superAdmin->email,
                    'phone' => $superAdmin->phone,
                ],
                'token'   => $token,
                'message' => 'Connexion Super-Admin réussie !',
            ]);
        }

        return response()->json([
            'status'  => 'error',
            'message' => 'Identifiants super-admin incorrects.'
        ], 401);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['status' => 'success', 'message' => 'Déconnexion réussie.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DASHBOARD — Statistiques globales
    // ─────────────────────────────────────────────────────────────────────────
    public function dashboardStats()
    {
        return response()->json([
            'total_establishments'    => Establishment::count(),
            'active_establishments'   => Establishment::where('is_active', true)->count(),
            'inactive_establishments' => Establishment::where('is_active', false)->count(),
            'total_users'             => User::count(),
            'total_academic_years'    => AcademicYears::count(),
            'total_team_members'      => SuperAdmin::count(),
            'recent_establishments'   => Establishment::latest()->take(5)->get(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ÉTABLISSEMENTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Liste tous les établissements avec compte d'utilisateurs et années.
     */
    public function listEstablishments(Request $request)
    {
        $query = Establishment::withCount(['users', 'academicYears']);

        // Filtre par statut
        if ($request->status === 'active')   $query->where('is_active', true);
        if ($request->status === 'inactive') $query->where('is_active', false);

        // Recherche
        if ($search = $request->search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                  ->orWhere('code', 'LIKE', "%{$search}%");
            });
        }

        return response()->json($query->orderBy('created_at', 'desc')->get());
    }

    /**
     * Détails d'un établissement (avec admins + années)
     */
    public function showEstablishment(string $id)
    {
        $establishment = Establishment::with(['academicYears'])->findOrFail($id);

        // Admins de l'école
        $adminRole = Role::where('establishment_id', $establishment->id)
            ->where('slug', 'admin')
            ->first();

        $admins = collect();
        if ($adminRole) {
            $admins = User::where('establishment_id', $establishment->id)
                ->whereHas('roles', fn($q) => $q->where('roles.id', $adminRole->id))
                ->get(['id', 'name', 'email', 'phone']);
        }

        // ─── AJOUT : infos groupe scolaire (parent + enfants) ───
        $parent = $establishment->parent_establishment_id
            ? Establishment::find($establishment->parent_establishment_id, ['id', 'name', 'code'])
            : null;

        $childrenCount = Establishment::where('parent_establishment_id', $establishment->id)->count();

        return response()->json([
            'establishment' => $establishment,
            'admins'        => $admins,
            'users_count'   => User::where('establishment_id', $establishment->id)->count(),
            'parent'        => $parent,
            'children_count'=> $childrenCount,
        ]);
    }

    /**
     * Crée un établissement + son admin école + optionnellement la 1ère année.
     * Peut aussi être créé directement comme ENFANT d'un groupe scolaire si
     * "parent_establishment_id" est fourni.
     */
    public function createEstablishment(Request $request, EstablishmentRoleService $roleService, \App\Services\EstablishmentPositionService $positionService)
    {
        $request->validate([
            // Établissement
            'name' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('establishments', 'code'),
            ],
            // Admin de l'école
            'admin_name'     => 'required|string|max:255',
            'admin_email'    => 'required|email|unique:users,email',
            'admin_phone'    => 'nullable|string|unique:users,phone',
            'admin_password' => 'required|string|min:6',
            // Année scolaire (optionnelle)
            'create_year'    => 'nullable|boolean',
            'year_name'      => 'required_if:create_year,true|nullable|string|max:255',
            'year_start'     => 'required_if:create_year,true|nullable|date',
            'year_end'       => 'required_if:create_year,true|nullable|date|after:year_start',
            // ─── AJOUT : groupe scolaire ───
            'parent_establishment_id' => 'nullable|exists:establishments,id',
            'child_quota'             => 'nullable|integer|min:0|max:20',
        ], [
            'code.unique'          => 'Ce code établissement est déjà utilisé.',
            'admin_email.unique'   => 'Cette adresse email est déjà utilisée par un autre compte.',
            'admin_phone.unique'   => 'Ce numéro est déjà utilisé par un autre compte.',
            'year_end.after'       => "La date de fin doit être postérieure à la date de début.",
            'year_name.required_if'=> "Le nom de l'année est requis si vous créez une année.",
        ]);

        // ─── Empêche les chaînes à plusieurs niveaux : un établissement
        // enfant ne peut jamais lui-même devenir parent d'un autre. ───
        if ($request->parent_establishment_id) {
            $parentCandidate = Establishment::find($request->parent_establishment_id);
            if ($parentCandidate && $parentCandidate->parent_establishment_id) {
                return response()->json([
                    'status'  => 'error',
                    'message' => "Impossible : l'établissement choisi comme parent est lui-même un établissement enfant. Un groupe scolaire ne peut avoir qu'un seul niveau de hiérarchie."
                ], 422);
            }
        }

        try {
            $result = DB::transaction(function () use ($request, $roleService, $positionService) {

                // 1. Créer l'établissement
                $establishment = Establishment::create([
                    'name'      => $request->name,
                    'code'      => strtoupper($request->code),
                    'is_active' => true,
                ]);

                // ─── Assignation DIRECTE des champs groupe scolaire, sans
                // dépendre du $fillable — évite le piège classique où
                // create([...]) ignore silencieusement un champ absent du
                // $fillable du modèle (aucune erreur, mais rien n'est écrit).
                $establishment->parent_establishment_id = $request->parent_establishment_id ?: null;
                $establishment->child_quota = $request->parent_establishment_id ? 0 : (int) ($request->child_quota ?? 0);
                $establishment->save();

                // 2. Créer les 4 rôles de base pour cet établissement
                $roleService->createDefaultRolesFor($establishment);

                // 2bis. Créer les 7 postes RH par défaut pour cet établissement
                $positionService->createDefaultPositions($establishment);

                // 3. Créer l'admin de l'école
                $admin = User::create([
                    'establishment_id' => $establishment->id,
                    'name'             => $request->admin_name,
                    'email'            => $request->admin_email,
                    'phone'            => $request->admin_phone,
                    'password'         => Hash::make($request->admin_password),
                ]);

                // 4. Lui assigner le rôle admin (SCOPÉ à cet établissement)
                $adminRole = Role::where('establishment_id', $establishment->id)
                    ->where('slug', 'admin')
                    ->first();
                if ($adminRole) {
                    $admin->assignRole($adminRole);
                }

                // 5. Créer optionnellement la 1ère année scolaire
                $year = null;
                if ($request->create_year) {
                    $year = AcademicYears::create([
                        'establishment_id' => $establishment->id,
                        'name'             => $request->year_name,
                        'start_date'       => $request->year_start,
                        'end_date'         => $request->year_end,
                        'is_active'        => true, // Année en cours par défaut
                        'is_archived'      => false,
                    ]);
                }

                return compact('establishment', 'admin', 'year');
            });

            return response()->json([
                'status'        => 'success',
                'message'       => "Établissement créé avec succès.",
                'establishment' => $result['establishment'],
                'admin'         => [
                    'id'    => $result['admin']->id,
                    'name'  => $result['admin']->name,
                    'email' => $result['admin']->email,
                    'phone' => $result['admin']->phone,
                ],
                'year'          => $result['year'],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Erreur création établissement : ' . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la création.',
                'debug'   => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Modifier un établissement (nom + code + quota d'établissements affiliés)
     */
    public function updateEstablishment(Request $request, string $id)
    {
        $establishment = Establishment::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('establishments', 'code')->ignore($establishment->id),
            ],
            // ─── AJOUT : modification du quota groupe scolaire ───
            'child_quota' => 'sometimes|integer|min:0|max:20',
        ]);

        // Le quota n'a de sens que sur un établissement qui n'est pas
        // lui-même un enfant (pas de sous-groupe à 2 niveaux).
        if ($request->has('child_quota') && $establishment->parent_establishment_id) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un établissement enfant ne peut pas avoir son propre quota d'établissements affiliés."
            ], 422);
        }

        $establishment->name = $request->name ?? $establishment->name;
        $establishment->code = $request->code ? strtoupper($request->code) : $establishment->code;
        if ($request->has('child_quota')) {
            $establishment->child_quota = (int) $request->child_quota;
        }
        $establishment->save();

        return response()->json([
            'status'        => 'success',
            'message'       => 'Établissement mis à jour.',
            'establishment' => $establishment,
        ]);
    }

    /**
     * Liste les établissements ENFANTS d'un établissement parent donné.
     * GET /superadmin/establishments/{id}/children
     */
    public function listEstablishmentChildren(string $id)
    {
        $establishment = Establishment::findOrFail($id);

        $children = Establishment::where('parent_establishment_id', $establishment->id)
            ->withCount(['users', 'academicYears'])
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'status'          => 'success',
            'parent'          => $establishment->only(['id', 'name', 'code', 'child_quota']),
            'children'        => $children,
            'quota_used'      => $children->count(),
            'quota_remaining' => max(0, $establishment->child_quota - $children->count()),
        ]);
    }

    /**
     * Basculer le statut actif/inactif d'un établissement.
     * Si désactivé → tous les utilisateurs de l'école ne peuvent plus se connecter.
     */
    public function toggleEstablishment(string $id)
    {
        $establishment = Establishment::findOrFail($id);
        $establishment->update(['is_active' => !$establishment->is_active]);

        // Si on désactive → révoker tous les tokens des users de cet établissement
        if (!$establishment->is_active) {
            User::where('establishment_id', $establishment->id)
                ->each(fn($u) => $u->tokens()->delete());
        }

        return response()->json([
            'status'        => 'success',
            'message'       => $establishment->is_active
                ? 'Établissement activé.'
                : 'Établissement désactivé. Les utilisateurs ne peuvent plus se connecter.',
            'establishment' => $establishment,
        ]);
    }

    /**
     * Supprimer un établissement (cascade sur users, rôles, années)
     */
    public function deleteEstablishment(string $id)
    {
        $establishment = Establishment::findOrFail($id);

        // ─── Empêche de supprimer un parent qui a encore des enfants ───
        $childrenCount = Establishment::where('parent_establishment_id', $establishment->id)->count();
        if ($childrenCount > 0) {
            return response()->json([
                'status'  => 'error',
                'message' => "Impossible de supprimer cet établissement : il a encore {$childrenCount} établissement(s) affilié(s). Supprimez-les d'abord, ou détachez-les."
            ], 422);
        }

        $name = $establishment->name;
        $establishment->delete(); // cascade défini dans les migrations

        return response()->json([
            'status'  => 'success',
            'message' => "L'établissement \"{$name}\" et toutes ses données ont été supprimés.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // COLLABORATEURS DE L'ÉQUIPE DEXSCHOOL
    // ─────────────────────────────────────────────────────────────────────────

    public function listTeamMembers()
    {
        return response()->json(
            SuperAdmin::orderBy('created_at', 'desc')->get(['id', 'name', 'email', 'phone', 'is_active', 'created_at'])
        );
    }

    public function createTeamMember(Request $request)
    {
        $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:super_admins,email',
            'phone'    => 'nullable|string|unique:super_admins,phone',
            'password' => 'required|string|min:6',
        ]);

        $member = SuperAdmin::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'phone'     => $request->phone,
            'password'  => Hash::make($request->password),
            'is_active' => true,
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'Collaborateur créé avec succès.',
            'member'  => [
                'id'    => $member->id,
                'name'  => $member->name,
                'email' => $member->email,
                'phone' => $member->phone,
            ],
        ], 201);
    }

    public function toggleTeamMember(string $id)
    {
        $member = SuperAdmin::findOrFail($id);
        $member->update(['is_active' => !$member->is_active]);

        if (!$member->is_active) {
            $member->tokens()->delete(); // Force la déconnexion
        }

        return response()->json([
            'status'  => 'success',
            'message' => $member->is_active
                ? 'Collaborateur réactivé.'
                : 'Collaborateur désactivé et déconnecté.',
            'member'  => $member,
        ]);
    }

    public function deleteTeamMember(Request $request, string $id)
    {
        // Empêcher de se supprimer soi-même
        if ($request->user()->id == $id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 403);
        }

        $member = SuperAdmin::findOrFail($id);
        $member->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Collaborateur supprimé.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROFIL DU SUPERADMIN CONNECTÉ
    // ─────────────────────────────────────────────────────────────────────────

    public function me(Request $request)
    {
        /** @var SuperAdmin $superAdmin */
        $superAdmin = $request->user();

        return response()->json([
            'status' => 'success',
            'admin'  => [
                'id'    => $superAdmin->id,
                'name'  => $superAdmin->name,
                'email' => $superAdmin->email,
                'phone' => $superAdmin->phone,
            ],
        ]);
    }

    public function updateProfile(Request $request)
    {
        /** @var SuperAdmin $superAdmin */
        $superAdmin = $request->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:150', Rule::unique('super_admins', 'email')->ignore($superAdmin->id)],
            'phone' => ['nullable', 'string', 'max:25', Rule::unique('super_admins', 'phone')->ignore($superAdmin->id)],
        ]);

        $superAdmin->update($validated);

        return response()->json([
            'status'  => 'success',
            'message' => 'Profil mis à jour avec succès.',
            'admin'   => [
                'id'    => $superAdmin->id,
                'name'  => $superAdmin->name,
                'email' => $superAdmin->email,
                'phone' => $superAdmin->phone,
            ],
        ]);
    }

    public function updatePassword(Request $request)
    {
        /** @var SuperAdmin $superAdmin */
        $superAdmin = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password'      => ['required', 'string', 'min:6', 'confirmed'],
        ], [
            'new_password.confirmed' => 'La confirmation du nouveau mot de passe ne correspond pas.',
        ]);

        if (!Hash::check($validated['current_password'], $superAdmin->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Le mot de passe actuel est incorrect.',
            ], 422);
        }

        $superAdmin->update(['password' => Hash::make($validated['new_password'])]);

        // Révoque les autres sessions actives, garde uniquement celle-ci
        $currentTokenId = $superAdmin->currentAccessToken()?->id;
        $superAdmin->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'status'  => 'success',
            'message' => 'Mot de passe modifié avec succès. Vos autres sessions ont été déconnectées.',
        ]);
    }
}