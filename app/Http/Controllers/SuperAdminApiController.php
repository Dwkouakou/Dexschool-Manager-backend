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

        return response()->json([
            'establishment' => $establishment,
            'admins'        => $admins,
            'users_count'   => User::where('establishment_id', $establishment->id)->count(),
        ]);
    }

    /**
     * Crée un établissement + son admin école + optionnellement la 1ère année.
     */
    // public function createEstablishment(Request $request, EstablishmentRoleService $roleService)
    // {
    //     $request->validate([
    //         // Établissement
    //         'name' => 'required|string|max:255',
    //         'code' => [
    //             'required', 'string', 'max:50',
    //             Rule::unique('establishments', 'code'),
    //         ],
    //         // Admin de l'école
    //         'admin_name'     => 'required|string|max:255',
    //         'admin_email'    => 'required|email|unique:users,email',
    //         'admin_phone'    => 'nullable|string|unique:users,phone',
    //         'admin_password' => 'required|string|min:6',
    //         // Année scolaire (optionnelle)
    //         'create_year'    => 'nullable|boolean',
    //         'year_name'      => 'required_if:create_year,true|nullable|string|max:255',
    //         'year_start'     => 'required_if:create_year,true|nullable|date',
    //         'year_end'       => 'required_if:create_year,true|nullable|date|after:year_start',
    //     ], [
    //         'code.unique'          => 'Ce code établissement est déjà utilisé.',
    //         'admin_email.unique'   => 'Cette adresse email est déjà utilisée par un autre compte.',
    //         'admin_phone.unique'   => 'Ce numéro est déjà utilisé par un autre compte.',
    //         'year_end.after'       => "La date de fin doit être postérieure à la date de début.",
    //         'year_name.required_if'=> "Le nom de l'année est requis si vous créez une année.",
    //     ]);

    //     try {
    //         $result = DB::transaction(function () use ($request, $roleService) {

    //             // 1. Créer l'établissement
    //             $establishment = Establishment::create([
    //                 'name'      => $request->name,
    //                 'code'      => strtoupper($request->code),
    //                 'is_active' => true,
    //             ]);

    //             // 2. Créer les 4 rôles de base pour cet établissement
    //             $roleService->createDefaultRolesFor($establishment);

    //             // 3. Créer l'admin de l'école
    //             $admin = User::create([
    //                 'establishment_id' => $establishment->id,
    //                 'name'             => $request->admin_name,
    //                 'email'            => $request->admin_email,
    //                 'phone'            => $request->admin_phone,
    //                 'password'         => Hash::make($request->admin_password),
    //             ]);

    //             // 4. Lui assigner le rôle admin (SCOPÉ à cet établissement)
    //             $adminRole = Role::where('establishment_id', $establishment->id)
    //                 ->where('slug', 'admin')
    //                 ->first();
    //             if ($adminRole) {
    //                 $admin->assignRole($adminRole);
    //             }

    //             // 5. Créer optionnellement la 1ère année scolaire
    //             $year = null;
    //             if ($request->create_year) {
    //                 $year = AcademicYears::create([
    //                     'establishment_id' => $establishment->id,
    //                     'name'             => $request->year_name,
    //                     'start_date'       => $request->year_start,
    //                     'end_date'         => $request->year_end,
    //                     'is_active'        => true, // Année en cours par défaut
    //                     'is_archived'      => false,
    //                 ]);
    //             }

    //             return compact('establishment', 'admin', 'year');
    //         });

    //         return response()->json([
    //             'status'        => 'success',
    //             'message'       => "Établissement créé avec succès.",
    //             'establishment' => $result['establishment'],
    //             'admin'         => [
    //                 'id'    => $result['admin']->id,
    //                 'name'  => $result['admin']->name,
    //                 'email' => $result['admin']->email,
    //                 'phone' => $result['admin']->phone,
    //             ],
    //             'year'          => $result['year'],
    //         ], 201);

    //     } catch (\Throwable $e) {
    //         Log::error('Erreur création établissement : ' . $e->getMessage());
    //         return response()->json([
    //             'status'  => 'error',
    //             'message' => 'Erreur lors de la création.',
    //             'debug'   => config('app.debug') ? $e->getMessage() : null,
    //         ], 500);
    //     }
    // }

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
    ], [
        'code.unique'          => 'Ce code établissement est déjà utilisé.',
        'admin_email.unique'   => 'Cette adresse email est déjà utilisée par un autre compte.',
        'admin_phone.unique'   => 'Ce numéro est déjà utilisé par un autre compte.',
        'year_end.after'       => "La date de fin doit être postérieure à la date de début.",
        'year_name.required_if'=> "Le nom de l'année est requis si vous créez une année.",
    ]);

    try {
        $result = DB::transaction(function () use ($request, $roleService, $positionService) {

            // 1. Créer l'établissement
            $establishment = Establishment::create([
                'name'      => $request->name,
                'code'      => strtoupper($request->code),
                'is_active' => true,
            ]);

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
     * Modifier un établissement (nom + code)
     */
    public function updateEstablishment(Request $request,string $id)
    {
        $establishment = Establishment::findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('establishments', 'code')->ignore($establishment->id),
            ],
        ]);

        $establishment->update([
            'name' => $request->name ?? $establishment->name,
            'code' => $request->code ? strtoupper($request->code) : $establishment->code,
        ]);

        return response()->json([
            'status'        => 'success',
            'message'       => 'Établissement mis à jour.',
            'establishment' => $establishment,
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
}