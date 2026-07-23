<?php

namespace Database\Seeders;

use App\Models\SuperAdmin;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //

        /**
     * Crée le compte SuperAdmin principal de DexSchool.
     * À exécuter UNE SEULE FOIS en production.
     */
  
        SuperAdmin::firstOrCreate(
            ['email' => 'dhlanwelling10@gmail.com'],
            [
                'name'      => 'Stephane',
                'password'  => Hash::make('stephane'),
                'is_active' => true,
            ]
        );

        $this->command->info("");
        $this->command->info("╔══════════════════════════════════════════════════════════════╗");
        $this->command->info("║   SUPER ADMIN CREE                                           ║");
        $this->command->info("╠══════════════════════════════════════════════════════════════╣");
        $this->command->info("║   Email    : dhlanwelling10@gmail.com                        ║");
        $this->command->info("║   Password : stephane                                        ║");
        $this->command->info("║   Endpoint : POST /api/superadmin/login                      ║");
        $this->command->info("╚══════════════════════════════════════════════════════════════╝");
        $this->command->info("");
    }
}
