<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeders.
     */
    public function run(): void
    {
         $this->call([
            RolesAndPermissionsSeeder::class,
            PositionSeeder::class,
            SuperAdminSeeder::class,
            // TestEstablishmentSeeder::class
        ]);
    }
}