<?php

namespace Database\Seeders;

use App\Models\Establishment;

use App\Services\EstablishmentPositionService;
use Illuminate\Database\Seeder;

class PositionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Ce seeder ne sert plus qu'au RATTRAPAGE : il crée le catalogue de
     * postes par défaut pour tous les établissements EXISTANTS qui n'en
     * ont pas encore (ex: ceux créés avant la mise en place du service
     * automatique). Pour toute NOUVELLE école créée depuis l'interface,
     * c'est EstablishmentPositionService qui s'en charge directement,
     * sans avoir besoin de relancer ce seeder.
     */
    public function run(): void
    {
        $service = new EstablishmentPositionService();
        $establishments = Establishment::all();

        foreach ($establishments as $establishment) {
            $service->createDefaultPositions($establishment);
        }

        $this->command->info('Postes RH par défaut vérifiés/créés pour ' . $establishments->count() . ' établissement(s).');
    }
}