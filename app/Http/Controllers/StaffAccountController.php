<?php

namespace App\Http\Controllers;

use App\Models\Personel\Employee;
use App\Models\User;
use Illuminate\Http\Request;

class StaffAccountController extends Controller
{
    /**
     * Liste des employés qui n'ont PAS encore de compte utilisateur
     * (employees.user_id est NULL). Sert au sélecteur du formulaire de
     * création de compte : l'admin choisit un employé existant, ses
     * informations (nom, email, téléphone) sont pré-remplies.
     * GET /api/staff/employees-without-account
     */
    public function employeesWithoutAccount()
    {
        // Employee a le trait BelongsToEstablishment -> déjà scopé
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
     * leur(s) rôle(s) assigné(s) et l'employé lié le cas échéant.
     * Sert au tableau "Comptes actifs" à côté du sélecteur d'employés
     * sans compte.
     * GET /api/staff/users-with-roles
     */
    public function usersWithRoles()
    {
        $establishmentId = current_establishment_id();

        $users = User::where('establishment_id', $establishmentId)
            ->with('roles:id,name,slug')
            ->orderBy('name')
            ->get();

        // Récupération en masse des employés liés (évite le N+1)
        $userIds = $users->pluck('id');
        $linkedEmployees = Employee::whereIn('user_id', $userIds)
            ->get(['id', 'user_id', 'matricule', 'position_id'])
            ->keyBy('user_id');

        $formatted = $users->map(function ($user) use ($linkedEmployees) {
            $employee = $linkedEmployees->get($user->id);

            return [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->roles->map(function ($role) {
                    return [
                        'id'   => $role->id,
                        'name' => $role->name,
                        'slug' => $role->slug,
                    ];
                }),
                'employee' => $employee ? [
                    'id'        => $employee->id,
                    'matricule' => $employee->matricule,
                ] : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'users'  => $formatted,
        ]);
    }

    /**
     * Modifie le(s) rôle(s) d'un utilisateur existant.
     * PUT /api/staff/users/{id}/roles
     */
    public function updateUserRoles(Request $request, string $id)
    {
        $establishmentId = current_establishment_id();

        $user = User::where('establishment_id', $establishmentId)->findOrFail($id);

        // "role_ids" peut être un tableau VIDE : ça correspond à une révocation
        // complète (l'utilisateur n'a plus aucun rôle assigné). Ce n'est plus
        // bloqué — juste un cas normal à gérer.
        $validated = $request->validate([
            'role_ids'   => ['present', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $roleIds = $validated['role_ids'] ?? [];

        // Sécurité : vérifie que tous les rôles demandés appartiennent bien
        // à CET établissement (pas de fuite inter-écoles via un ID deviné)
        $roles = \Spatie\Permission\Models\Role::where('establishment_id', $establishmentId)
            ->whereIn('id', $roleIds)
            ->get();

        if ($roles->count() !== count($roleIds)) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un ou plusieurs rôles sélectionnés n'appartiennent pas à votre établissement.",
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
     * Crée un compte utilisateur à partir d'un employé existant sans compte,
     * en lui assignant un ou plusieurs rôles d'un coup. Lie ensuite
     * employees.user_id au compte fraîchement créé.
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

        // Sécurité : les rôles doivent appartenir à CET établissement
        $roles = \Spatie\Permission\Models\Role::where('establishment_id', $establishmentId)
            ->whereIn('id', $validated['role_ids'])
            ->get();

        if ($roles->count() !== count($validated['role_ids'])) {
            return response()->json([
                'status'  => 'error',
                'message' => "Un ou plusieurs rôles sélectionnés n'appartiennent pas à votre établissement.",
            ], 422);
        }

        // Empêche la création d'un compte Administrateur via ce flux
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
     * Supprime définitivement un compte utilisateur et, si un employé lui
     * était lié, le fait automatiquement RETOMBER dans "Sans compte"
     * (employees.user_id remis à null) — il pourra se voir recréer un
     * compte plus tard sans avoir à recréer son dossier RH.
     * DELETE /api/staff/users/{id}
     */
    public function destroyUserAccount(string $id)
    {
        $establishmentId = current_establishment_id();

        $user = User::where('establishment_id', $establishmentId)->findOrFail($id);

        // Sécurité : on ne supprime jamais un compte Administrateur via ce
        // flux générique — ce cas doit passer par une procédure dédiée.
        $isAdmin = $user->roles()->where('slug', 'admin')->exists();
        if ($isAdmin) {
            return response()->json([
                'status'  => 'error',
                'message' => "Le compte Administrateur ne peut pas être supprimé depuis cette interface.",
            ], 403);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            $userName = $user->name;

            // Retire tous les rôles (nettoyage de la table pivot)
            \Illuminate\Support\Facades\DB::table('model_has_roles')
                ->where('model_id', $user->id)
                ->where('model_type', \App\Models\User::class)
                ->delete();

            // Détache l'employé éventuellement lié — il repasse "Sans compte"
            $linkedEmployee = Employee::where('user_id', $user->id)->first();
            if ($linkedEmployee) {
                $linkedEmployee->update(['user_id' => null]);
            }

            // Révoque tous les tokens Sanctum actifs de ce compte
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