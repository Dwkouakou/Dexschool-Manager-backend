<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : peuple le catalogue avec les modules connus à ce jour.
     * Transport est marqué actif par défaut (déjà entièrement audité).
     * Cantine et Bibliothèque sont marqués inactifs par défaut (audits
     * encore en cours / récents) — les établissements qui en ont
     * réellement besoin dès aujourd'hui seront réactivés manuellement,
     * un par un, depuis le SuperAdmin.
     */
    public function up(): void
    {
        $modules = [
            ['key' => 'students',       'label' => 'Élèves & Inscriptions',   'is_active_by_default' => true],
            ['key' => 'payments',       'label' => 'Scolarité & Paiements',   'is_active_by_default' => true],
            ['key' => 'academic_years', 'label' => 'Années Scolaires',        'is_active_by_default' => true],
            ['key' => 'classes',        'label' => 'Cycles & Classes',        'is_active_by_default' => true],
            ['key' => 'hr',             'label' => 'Personnel RH',            'is_active_by_default' => true],
            ['key' => 'transport',      'label' => 'Transport Scolaire',      'is_active_by_default' => true],
            ['key' => 'canteen',        'label' => 'Cantine Scolaire',        'is_active_by_default' => false],
            ['key' => 'library',        'label' => 'Bibliothèque',            'is_active_by_default' => false],
            ['key' => 'reporting',      'label' => 'Rapports & Statistiques', 'is_active_by_default' => true],
        ];

        foreach ($modules as $module) {
            DB::table('modules')->updateOrInsert(
                ['key' => $module['key']],
                array_merge($module, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('modules')->whereIn('key', [
            'students', 'payments', 'academic_years', 'classes',
            'hr', 'transport', 'canteen', 'library', 'reporting',
        ])->delete();
    }
};