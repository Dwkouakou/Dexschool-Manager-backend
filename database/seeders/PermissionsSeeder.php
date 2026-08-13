<?php

namespace Database\Seeders;

use App\Support\PermissionCategories;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    /**
     * Crée en dur toutes les permissions techniques de l'application.
     *
     * Ces permissions sont GLOBALES (pas d'establishment_id) : elles décrivent
     * ce que le code sait faire. Les rôles (qui sont par établissement)
     * pourront ensuite piocher dans cette liste.
     *
     * Format : {module}.{action}
     * Chaque permission reçoit aussi une "group" (catégorie/étiquette),
     * dérivée automatiquement du module via PermissionCategories.
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ─── Structure ainsi permissions par module ──────────────────────────
        $permissionsByModule = [

            // ═══ Configuration & Système ═══
            'academic_years'   => ['view', 'create', 'edit', 'delete', 'activate', 'archive'],
            'periods'          => ['view', 'generate', 'edit', 'activate'],
            'cycles'           => ['view', 'create', 'edit', 'delete'],
            'levels'           => ['view', 'create', 'edit', 'delete'],
            'classes'          => ['view', 'create', 'edit', 'delete', 'duplicate'], // NOUVEAU: duplicate
            'subjects'         => ['view', 'create', 'edit', 'delete', 'activate'],
            'settings'         => ['view', 'edit'],

            // ═══ Scolarité ═══
            'students'         => ['view', 'create', 'edit', 'delete', 'restore', 'export', 'import'], // NOUVEAU: import
            'enrollments'      => ['view', 'create', 'validate', 'cancel', 'reenroll'],
            'documents'        => ['view', 'upload', 'delete'],

            // ═══ Finances ═══
            'payments'         => ['view', 'create', 'validate', 'refund'],
            'receipts'         => ['view', 'print'],
            'financial_reports'=> ['view', 'export'],

            // ═══ RH ═══
            'employees'        => ['view', 'create', 'edit', 'delete'],
            'teachers'         => ['view', 'assign_classes'],
            'contracts'        => ['view', 'create', 'terminate'],
            'payrolls'         => ['view', 'generate', 'print'],
            'positions'        => ['view', 'create', 'edit', 'delete'], // NOUVEAU
            'teacher_attributions' => ['view', 'create', 'delete'], // NOUVEAU

            // ═══ Notes & Bulletins ═══
            'evaluations'      => ['view', 'create', 'edit', 'delete'],
            'grades'           => ['view', 'enter', 'edit', 'publish'], //notes
            'averages'         => ['view', 'calculate', 'validate'], // moyennes
            'report_cards'     => ['view', 'generate', 'print'], // bulletins scolaires

            // ═══ Cantine ═══
            'canteen'          => ['view'],
            'canteen_subscriptions' => ['view', 'create', 'edit', 'delete', 'collect_payment'],
            'canteen_stock'    => ['view', 'manage'],
            'canteen_expenses' => ['view', 'create', 'edit', 'delete'],

            // ═══ Transport ═══
            'transport'        => ['view'],
            'vehicles'         => ['view', 'create', 'edit', 'delete'],
            'transport_routes' => ['view', 'create', 'edit', 'delete'], // NOUVEAU
            'transport_subscriptions' => ['view', 'create', 'edit', 'delete', 'collect_payment'],
            'transport_expenses'      => ['view', 'create', 'edit', 'delete'],

            // ═══ Bibliothèque ═══
            'library'          => ['view'],
            'books'            => ['view', 'create', 'edit', 'delete'],
            'book_categories'  => ['view', 'create', 'edit', 'delete'], // NOUVEAU
            'book_loans'       => ['view', 'create', 'return'],

            // ═══ Administration ═══
            'users'            => ['view', 'create', 'edit', 'delete', 'reset_password'],
            'roles'            => ['view', 'create', 'edit', 'delete'],
            'reports'          => ['view', 'export'],
            'dashboard'        => ['view'],
            'activity_logs'    => ['view'], // NOUVEAU : journal d'activité établissement
        ];

        $count = 0;
        foreach ($permissionsByModule as $module => $actions) {
            $group = PermissionCategories::MODULE_TO_CATEGORY[$module] ?? null;

            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    [
                        'name'       => "{$module}.{$action}",
                        'guard_name' => 'web',
                    ],
                    [
                        'group' => $group,
                    ]
                );

                // Si la permission existait déjà (ancien seed sans group), on
                // met quand même à jour sa catégorie pour rester cohérent.
                Permission::where('name', "{$module}.{$action}")
                    ->where('guard_name', 'web')
                    ->update(['group' => $group]);

                $count++;
            }
        }

        $this->command->info("✓ {$count} permissions techniques créées/mises à jour, réparties en " . count(PermissionCategories::CATEGORIES) . " catégories.");
    }
}