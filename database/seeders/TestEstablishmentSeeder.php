<?php

namespace Database\Seeders;

use App\Models\Academic\AcademicYears;
use App\Models\Establishment;
use App\Models\User;
use App\Services\EstablishmentRoleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestEstablishmentSeeder extends Seeder
{
    public function run(EstablishmentRoleService $roleService): void
    {
        // ─── Établissement de test ──────────────────────────────────────
        $establishment = Establishment::firstOrCreate(
            ['code' => 'MFO-001'],
            [
                'name'      => 'Groupe Scolaire Mariam Fofana',
                'is_active' => true,
            ]
        );

        // ─── Créer automatiquement les 4 rôles de base pour cet établissement ──
        $roleService->createDefaultRolesFor($establishment);

        // ─── Année scolaire passée ──────────────────────────────────────
        AcademicYears::firstOrCreate(
            ['establishment_id' => $establishment->id, 'name' => '2024-2025'],
            [
                'start_date'  => '2024-09-01',
                'end_date'    => '2025-07-15',
                'is_active'   => false,
                'is_archived' => false,
            ]
        );

        // ─── Année scolaire active ──────────────────────────────────────
        AcademicYears::firstOrCreate(
            ['establishment_id' => $establishment->id, 'name' => '2025-2026'],
            [
                'start_date'  => '2025-09-01',
                'end_date'    => '2026-07-15',
                'is_active'   => true,
                'is_archived' => false,
            ]
        );

        // ─── Admin de l'établissement ───────────────────────────────────
        $admin = User::firstOrCreate(
            [
                'email'            => 'admin@mariam-fofana.ci',
                'establishment_id' => $establishment->id,
            ],
            [
                'name'     => 'Fofana Collete',
                'phone'    => '0707070707',
                'password' => Hash::make('password123'),
            ]
        );

        // ─── Assigner le rôle 'admin' SCOPÉ à cet établissement ─────────
        // Attention : on récupère le rôle par slug + establishment_id
        // pas par nom, pour éviter tout conflit entre écoles
        $adminRole = \Spatie\Permission\Models\Role::where('establishment_id', $establishment->id)
            ->where('slug', 'admin')
            ->first();

        if ($adminRole && !$admin->hasRole($adminRole)) {
            $admin->assignRole($adminRole);
        }

        $this->command->info("");
        $this->command->info("╔══════════════════════════════════════════════════════════════╗");
        $this->command->info("║   ETABLISSEMENT DE TEST CREE                                 ║");
        $this->command->info("╠══════════════════════════════════════════════════════════════╣");
        $this->command->info("║   Code     : MFO-001                                         ║");
        $this->command->info("║   Login    : admin@mariam-fofana.ci  OU  0707070707          ║");
        $this->command->info("║   Password : password123                                     ║");
        $this->command->info("║   Rôle     : Administrateur (verrouillé, toutes permissions) ║");
        $this->command->info("╚══════════════════════════════════════════════════════════════╝");
        $this->command->info("");
    }
}