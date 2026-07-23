<?php

namespace Database\Seeders;

use App\Support\PermissionCategories;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $permissionsByModule = [
            'academic_years'   => ['view', 'create', 'edit', 'delete', 'activate', 'archive'],
            'periods'          => ['view', 'generate', 'edit', 'activate'],
            'cycles'           => ['view', 'create', 'edit', 'delete'],
            'levels'           => ['view', 'create', 'edit', 'delete'],
            'classes'          => ['view', 'create', 'edit', 'delete', 'duplicate'],
            'subjects'         => ['view', 'create', 'edit', 'delete', 'activate'],
            'settings'         => ['view', 'edit'],

            'students'         => ['view', 'create', 'edit', 'delete', 'restore', 'export', 'import'],
            'enrollments'      => ['view', 'create', 'validate', 'cancel', 'reenroll'],
            'documents'        => ['view', 'upload', 'delete'],

            'payments'         => ['view', 'create', 'validate', 'refund'],
            'receipts'         => ['view', 'print'],
            'financial_reports'=> ['view', 'export'],

            'employees'        => ['view', 'create', 'edit', 'delete'],
            'teachers'         => ['view', 'assign_classes'],
            'contracts'        => ['view', 'create', 'terminate'],
            'payrolls'         => ['view', 'generate', 'print'],
            'positions'        => ['view', 'create', 'edit', 'delete'],
            'teacher_attributions' => ['view', 'create', 'delete'],

            'evaluations'      => ['view', 'create', 'edit', 'delete'],
            'grades'           => ['view', 'enter', 'edit', 'publish'],
            'averages'         => ['view', 'calculate', 'validate'],
            'report_cards'     => ['view', 'generate', 'print'],

            'canteen'          => ['view'],
            'canteen_subscriptions' => ['view', 'create', 'edit', 'delete', 'collect_payment'],
            'canteen_stock'    => ['view', 'manage'],
            'canteen_expenses' => ['view', 'create', 'edit', 'delete'],

            'transport'        => ['view'],
            'vehicles'         => ['view', 'create', 'edit', 'delete'],
            'transport_routes' => ['view', 'create', 'edit', 'delete'],
            'transport_subscriptions' => ['view', 'create', 'edit', 'delete', 'collect_payment'],
            'transport_expenses'      => ['view', 'create', 'edit', 'delete'],

            'library'          => ['view'],
            'books'            => ['view', 'create', 'edit', 'delete'],
            'book_categories'  => ['view', 'create', 'edit', 'delete'],
            'book_loans'       => ['view', 'create', 'return'],

            'users'            => ['view', 'create', 'edit', 'delete', 'reset_password'],
            'roles'            => ['view', 'create', 'edit', 'delete'],
            'reports'          => ['view', 'export'],
            'dashboard'        => ['view'],
        ];

        $count = 0;
        foreach ($permissionsByModule as $module => $actions) {
            $group = PermissionCategories::MODULE_TO_CATEGORY[$module] ?? null;

            foreach ($actions as $action) {
                Permission::firstOrCreate(
                    ['name' => "{$module}.{$action}", 'guard_name' => 'web'],
                    ['group' => $group]
                );

                Permission::where('name', "{$module}.{$action}")
                    ->where('guard_name', 'web')
                    ->update(['group' => $group]);

                $count++;
            }
        }

        $this->command->info("✓ {$count} permissions créées/mises à jour, réparties en " . count(PermissionCategories::CATEGORIES) . " catégories.");
    }
}