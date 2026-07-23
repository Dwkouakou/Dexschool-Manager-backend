<?php

namespace App\Console\Commands;

use App\Models\officeAdministration\Enrollment;
use App\Models\User;
use Illuminate\Console\Command;

class RepairEnrollmentsData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
     protected $signature = 'enrollments:repair';


    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rattrapage des identifiants créateurs et dates sur les anciennes inscriptions';
   

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // 1. Trouver l'ID de l'admin Stephane
        $adminId = User::where('email', 'dhlanwelling10@gmail.com')->value('id') ?? 1;

        // 2. Récupérer uniquement les inscriptions corrompues
        $corrupted = Enrollment::whereNull('created_by')->get();

        if ($corrupted->isEmpty()) {
            $this->info('Parfait ! Aucune inscription corrompue détectée.');
            return 0;
        }

        $this->info("Traitement de " . $corrupted->count() . " inscriptions en cours...");

        foreach ($corrupted as $enr) {
            $updateData = [
                'created_by' => $adminId // Assigne d'office à Stephane
            ];

            // Si l'inscription était déjà marquée comme validée à l'écran, on lui donne une date cohérente
            if ($enr->status === 'validated' && null === $enr->validated_at) {
                $updateData['validated_by'] = $adminId;
                $updateData['validated_at'] = $enr->created_at; // On prend sa date de création comme repère
            }

            $enr->update($updateData);
        }

        $this->info('Données financières et traçabilité synchronisées avec succès !');
        return 0;
    }
}
