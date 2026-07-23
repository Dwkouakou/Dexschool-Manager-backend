<?php

namespace App\Services;

use App\Models\Establishment;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EstablishmentRoleService
{
    /**
     * Définition des 4 rôles de base créés automatiquement à chaque
     * nouvel établissement.
     *
     * Structure :
     *   slug        → identifiant technique stable (ne change JAMAIS)
     *   name        → libellé affiché (l'école peut le renommer si non locked)
     *   description → texte explicatif dans l'UI
     *   is_locked   → true = interdit à toute modification (Admin uniquement)
     *   permissions → tableau de permissions OU '*' pour toutes
     */
    protected array $baseRoles = [
        [
            'slug'        => 'admin',
            'name'        => 'Administrateur',
            'description' => 'Accès complet à tous les modules. Rôle système verrouillé.',
            'is_locked'   => true,
            'permissions' => '*', // Toutes les permissions
        ],
        [
            'slug'        => 'directeur',
            'name'        => 'Directeur',
            'description' => 'Direction, supervision pédagogique et vue globale.',
            'is_locked'   => false,
            'permissions' => [
                // Dashboard et rapports
                'dashboard.view', 'reports.view', 'reports.export',
                // Élèves (lecture + gestion)
                'students.view', 'students.create', 'students.edit', 'students.export',
                'enrollments.view', 'enrollments.validate',
                // Configuration
                'academic_years.view', 'periods.view', 'classes.view', 'subjects.view',
                'cycles.view', 'levels.view',
                // Notes
                'evaluations.view', 'grades.view', 'averages.view', 'averages.validate',
                'report_cards.view', 'report_cards.generate',
                // Finances (lecture seule)
                'payments.view', 'receipts.view', 'financial_reports.view', 'financial_reports.export',
                // RH (lecture)
                'employees.view', 'teachers.view', 'payrolls.view',
                // Services
                'canteen.view', 'transport.view', 'library.view',
                // Utilisateurs
                'users.view',
            ],
        ],
        [
            'slug'        => 'comptable',
            'name'        => 'Comptable',
            'description' => 'Encaissements, reçus et rapports financiers.',
            'is_locked'   => false,
            'permissions' => [
                'dashboard.view',
                'students.view',
                'enrollments.view',
                'payments.view', 'payments.create', 'payments.validate',
                'receipts.view', 'receipts.print',
                'financial_reports.view', 'financial_reports.export',
                'canteen_subscriptions.view', 'canteen_subscriptions.collect_payment',
                'transport_subscriptions.view', 'transport_subscriptions.collect_payment',
            ],
        ],
        [
            'slug'        => 'enseignant',
            'name'        => 'Enseignant',
            'description' => 'Saisie des notes et consultation des élèves de ses classes.',
            'is_locked'   => false,
            'permissions' => [
                'dashboard.view',
                'students.view',
                'classes.view', 'subjects.view',
                'evaluations.view', 'evaluations.create', 'evaluations.edit',
                'grades.view', 'grades.enter', 'grades.edit',
                'averages.view',
                'report_cards.view',
                'periods.view',
            ],
        ],
    ];

    /**
     * Crée les rôles de base pour un établissement donné.
     * Appelé automatiquement lors de la création d'un nouvel établissement.
     *
     * @param  Establishment  $establishment
     * @return array  Rôles créés (pour éventuelle réponse API)
     */
    public function createDefaultRolesFor(Establishment $establishment): array
    {
        // Reset du cache Spatie
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $created = [];

        foreach ($this->baseRoles as $roleDef) {
            $role = Role::firstOrCreate(
                [
                    'establishment_id' => $establishment->id,
                    'slug'             => $roleDef['slug'],
                    'guard_name'       => 'web',
                ],
                [
                    'name'        => $roleDef['name'],
                    'description' => $roleDef['description'],
                    'is_system'   => true,
                    'is_locked'   => $roleDef['is_locked'],
                ]
            );

            // Attribuer les permissions
            if ($roleDef['permissions'] === '*') {
                // Toutes les permissions
                $role->syncPermissions(Permission::all());
            } else {
                // Uniquement celles listées
                $permissions = Permission::whereIn('name', $roleDef['permissions'])->get();
                $role->syncPermissions($permissions);
            }

            $created[] = $role;
        }

        return $created;
    }

    /**
     * Vérifie qu'un utilisateur ne peut pas modifier un rôle verrouillé
     * ou supprimer un rôle système.
     */
    public function canEdit(Role $role): bool
    {
        return !$role->is_locked;
    }

    public function canDelete(Role $role): bool
    {
        return !$role->is_system && !$role->is_locked;
    }
}