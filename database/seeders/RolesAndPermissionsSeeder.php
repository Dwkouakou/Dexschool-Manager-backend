<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * NOUVELLE APPROCHE :
 *
 * On ne crée PLUS de rôles globaux ici.
 *
 * Les rôles sont scopés par établissement et créés automatiquement à la
 * naissance d'une école via App\Services\EstablishmentRoleService.
 *
 * Ici, on crée uniquement les PERMISSIONS globales (le catalogue des actions
 * techniques que le code sait faire).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // On délègue au seeder dédié
        $this->call([
            PermissionsSeeder::class,
        ]);

        $this->command->info("✓ Rôles : ils seront créés automatiquement à la naissance de chaque établissement.");
    }
}