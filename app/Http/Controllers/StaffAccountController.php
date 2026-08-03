<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\Personel\Employee;
use App\Models\User;
use Illuminate\Http\Request;

class StaffAccountController extends Controller
{
    /**
     * Renvoie la liste des IDs d'établissements du GROUPE de l'établissement
     * actuellement consulté (lui-même compris) — ou juste lui-même s'il ne
     * fait partie d'aucun groupe. Sert à valider les attributions de rôles
     * multi-cycle : un rôle ne peut être attribué que dans le même groupe,
     * jamais chez un client totalement différent.
     */
    private function currentGroupEstablishmentIds(): array
    {
        $establishmentId = current_establishment_id();
        $establishment = Establishment::find($establishmentId);

        if (!$establishment) {
            return [$establishmentId];
        }

        $rootId = $establishment->parent_establishment_id ?? $establishment->id;

        return Establishment::where('id', $rootId)
            ->orWhere('parent_establishment_id', $rootId)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Liste des rôles disponibles pour TOUT le groupe scolaire, groupés par
     * établissement — utilisée uniquement par l'interface quand l'employé a
     * "multi_cycle_access" activé.
     * GET /api/staff/group-roles
     */
    public function groupRoles()
    {
        $groupIds = $this->currentGroupEstablishmentIds();
        $currentId = current_establishment_id();

        $establishments = Establishment::whereIn('id', $groupIds)
            ->orderByRaw('id = ' . (int) $currentId . ' DESC')
            ->get(['id', 'name', 'code']);

        $result = $establishments->map(function ($est) use ($currentId) {
            $roles = \Spatie\Permission\Models\Role::where('establishment_id', $est->id)
                ->where('slug', '!=', 'admin')
                ->orderBy('is_locked', 'desc')
                ->orderBy('name')
                ->get(['id', 'name', 'slug']);

            return [
                'establishment_id'   => $est->id,
                'establishment_name' => $est->name,
                'establishment_code' => $est->code,
                'is_current'         => $est->id === $currentId,
                'roles'              => $roles,
            ];
        });

        return response()->json([
            'status'         => 'success',
            'is_group'       => count($groupIds) > 1,
            'establishments' => $result,
        ]);
    }

    /**
     * Liste des employés qui n'ont PAS encore de compte utilisateur.
     * GET /api/staff/employees-without-account
     */
    public function employeesWithoutAccount()
    {
        $employees = Employee::with('position')
            ->whereNull('user_id')
            ->where('status', 'active')
            ->orderBy('last_name')
            ->get()
            ->map(function ($emp) {
                return [
                    'id'         => $emp->id,
                    'matricule'  => $emp->matricule,
                    'first_name' => $emp->first_name,
                    'last_name'  => $emp->last_name,
                    'email'      => $emp->email,
                    'phone'      => $emp->phone,
                    'photo'      => $emp->photo,
                    'multi_cycle_access' => (bool) $emp->multi_cycle_access,
                    'position'   => $emp->position ? [
                        'id'   => $emp->position->id,
                        'name' => $emp->position->name,
                    ] : null,
                ];
            });

        return response()->json([
            'status'    => 'success',
            'employees' => $employees,
        ]);
    }

    /**
     * Liste des comptes utilisateurs existants de l'établissement, avec
     * leur(s) rôle(s) assigné(s) — annotés de l'établissement d'origine de
     * chaque rôle pour distinguer les rôles multi-cycle.
     * GET /api/staff/users-with-roles
     */
    public function usersWithRoles()
    {
        $establishmentId = current_establishment_id();

        $users = User::where('establishment_id', $establishmentId)
            ->with('roles:id,name,slug,establishment_id')
            ->orderBy('name')
            ->get();

        $userIds = $users->pluck('id');
        $linkedEmployees = Employee::whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'matricule', 'position_id', 'multi_cycle_access'])
            ->keyBy('user_id');

        $groupEstablishments = Establishment::whereIn('id', $this->currentGroupEstablishmentIds())
            ->get(['id', 'name'])
            ->keyBy('id');

        $formatted = $users->map(function ($user) use ($linkedEmployees, $groupEstablishments, $establishmentId) {
            $employee = $linkedEmployees->get($user->id);

            return [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->map(function ($role) use ($groupEstablishments, $establishmentId) {
                    return [
                        'id'                     => $role->id,
                        'name'                   => $role->name,
                        'slug'                   => $role->slug,
                        'establishment_id'       => $role->establishment_id,
                        'establishment_name'     => $groupEstablishments->get($role->establishment_id)?->name,
                        'is_cross_establishment' => $role->establishment_id !== $establishmentId,
                    ];
                }),
                'employee' => $employee ? [
                    'id'                 => $employee->id,
                    'matricule'          => $employee->matricule,
                    'multi_cycle_access' => (bool) $employee->multi_cycle_access,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'users'  => $formatted,
        ]);
    }

    /**
     * Modifie le(s) rôle(s) d'un utilisateur existant. Si l'employé lié a
     * "multi_cycle_access" activé, les rôles peuvent appartenir à n'importe
     * quel établissement du même groupe scolaire.
     * PUT /api/staff/users/{id}/roles
     */
    public function updateUserRoles(Request $request, string $id)
    {
        $establishmentId = current_establishment_id();

        $user = User::where('establishment_id', $establishmentId)->findOrFail($id);

        $validated = $request->validate([
            'role_ids'   => ['present', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $roleIds = $validated['role_ids'] ?? [];

        $linkedEmployee = Employee::where('user_id', $user->id)->first();
        $allowedEstablishmentIds = ($linkedEmployee && $linkedEmployee->multi_cycle_access)
            ? $this->currentGroupEstablishmentIds()
            : [$establishmentId];

        $roles = \Spatie\Permission\Models\Role::whereIn('establishment_id', $allowedEstablishmentIds)
            ->whereIn('id', $roleIds)
            ->get();

        if ($roles->count() !== count($roleIds)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un ou plusieurs rôles sélectionnés n'appartiennent pas à votre établissement" .
                    (count($allowedEstablishmentIds) > 1 ? " ou à son groupe scolaire." : "."),
            ], 422);
        }

        $user->syncRoles($roles);

        $message = $roles->isEmpty()
            ? "Tous les rôles de {$user->name} ont été révoqués. Il/elle n'a plus aucun accès."
            : "Rôles de {$user->name} mis à jour.";

        return response()->json([
            'status'  => 'success',
            'message' => $message,
            'roles'   => $roles->pluck('name'),
        ]);
    }

    /**
     * Crée un compte utilisateur à partir d'un employé sans compte. Si
     * l'employé a "multi_cycle_access" activé, les rôles fournis peuvent
     * couvrir plusieurs établissements du même groupe en un seul appel.
     * POST /api/staff/employees/{id}/create-account
     */
    public function createAccountForEmployee(Request $request, string $id)
    {
        $establishmentId = current_establishment_id();

        $employee = Employee::where('user_id', null)->findOrFail($id);

        $validated = $request->validate([
            'email'      => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone'      => ['nullable', 'string', 'max:25'],
            'password'   => ['required', 'string', 'min:6'],
            'role_ids'   => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ], [
            'role_ids.required' => 'Sélectionnez au moins un rôle.',
            'email.unique'      => 'Cette adresse email est déjà utilisée par un autre compte.',
        ]);

        $allowedEstablishmentIds = $employee->multi_cycle_access
            ? $this->currentGroupEstablishmentIds()
            : [$establishmentId];

        $roles = \Spatie\Permission\Models\Role::whereIn('establishment_id', $allowedEstablishmentIds)
            ->whereIn('id', $validated['role_ids'])
            ->get();

        if ($roles->count() !== count($validated['role_ids'])) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un ou plusieurs rôles sélectionnés n'appartiennent pas à votre établissement" .
                    (count($allowedEstablishmentIds) > 1 ? " ou à son groupe scolaire." : "."),
            ], 422);
        }

        if ($roles->contains('slug', 'admin')) {
            return response()->json([
                'status'  => 'error',
                'message' => "Le rôle Administrateur ne peut pas être attribué depuis ce formulaire.",
            ], 422);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($employee, $establishmentId, $validated, $roles) {
            $user = User::create([
                'establishment_id' => $establishmentId,
                'name'             => trim($employee->first_name . ' ' . $employee->last_name),
                'email'            => $validated['email'],
                'phone'            => $validated['phone'] ?? $employee->phone,
                'password'         => \Illuminate\Support\Facades\Hash::make($validated['password']),
            ]);

            $user->syncRoles($roles);

            $employee->update(['user_id' => $user->id]);

            return response()->json([
                'status'  => 'success',
                'message' => "Compte créé pour {$user->name} avec " . $roles->count() . " rôle(s) assigné(s).",
                'user'    => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $roles->pluck('name'),
                ],
            ], 201);
        });
    }

    /**
     * Supprime définitivement un compte utilisateur.
     * DELETE /api/staff/users/{id}
     */
    public function destroyUserAccount(string $id)
    {
        $establishmentId = current_establishment_id();

        $user = User::where('establishment_id', $establishmentId)->findOrFail($id);

        $isAdmin = $user->roles()->where('slug', 'admin')->exists();
        if ($isAdmin) {
            return response()->json([
                'status'  => 'error',
                'message' => "Le compte Administrateur ne peut pas être supprimé depuis cette interface.",
            ], 403);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            $userName = $user->name;

            \Illuminate\Support\Facades\DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', \App\Models\User::class)
                ->delete();

            $linkedEmployee = Employee::where('user_id', $user->id)->first();
            if ($linkedEmployee) {
                $linkedEmployee->update(['user_id' => null]);
            }

            $user->tokens()->delete();

            $user->delete();

            return response()->json([
                'status'  => 'success',
                'message' => $linkedEmployee
                    ? "Compte de {$userName} supprimé. {$linkedEmployee->first_name} {$linkedEmployee->last_name} est repassé(e) dans les employés sans compte."
                    : "Compte de {$userName} supprimé avec succès.",
            ]);
        });
    }
}