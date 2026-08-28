<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : passe le catalogue de 9 à 21 modules, alignés avec la
     * vraie sidebar (tous les liens de menu ont maintenant leur clé).
     * SÛR pour la prod : establishment_module_access était vide au moment
     * de cette migration (aucune exception établissement à casser), et
     * updateOrInsert() ne supprime jamais un module déjà en place — les 7
     * clés déjà correctes (students, payments, academic_years, transport,
     * canteen, library, reporting) sont juste laissées telles quelles.
     * Les 2 clés devenues obsolètes ('classes', 'hr' — remplacées par une
     * granularité plus fine) sont retirées puisqu'aucune exception n'y
     * est jamais rattachée.
     */
    public function up(): void
    {
        // Retire les 2 clés génériques remplacées par des clés plus fines
        DB::table('modules')->whereIn('key', ['classes', 'hr'])->delete();

        $modules = [
            ['key' => 'students',                  'label' => 'Dossiers Élèves',                       'is_active_by_default' => true],
            ['key' => 'enrollments',                'label' => 'Inscriptions / Réinscriptions',         'is_active_by_default' => true],
            ['key' => 'payments',                   'label' => 'Encaissements / Paiements',             'is_active_by_default' => true],
            ['key' => 'financial_reports',          'label' => 'Bilan Scolarité & Bilan du Groupe',     'is_active_by_default' => true],
            ['key' => 'hr_employees',               'label' => 'Personnel Administratif',               'is_active_by_default' => true],
            ['key' => 'hr_teachers',                'label' => 'Enseignants',                           'is_active_by_default' => true],
            ['key' => 'hr_payroll',                 'label' => 'Livre des salaires',                    'is_active_by_default' => true],
            ['key' => 'grades',                     'label' => 'Notes & Résultats (côté professeur)',   'is_active_by_default' => true],
            ['key' => 'report_cards',               'label' => 'Bulletins élèves (côté administration)','is_active_by_default' => true],
            ['key' => 'academic_years',             'label' => 'Années Académiques',                    'is_active_by_default' => true],
            ['key' => 'academic_structure',         'label' => 'Cycles, Niveaux & Classes',             'is_active_by_default' => true],
            ['key' => 'periods',                    'label' => 'Découpage & Périodes',                  'is_active_by_default' => true],
            ['key' => 'subjects',                   'label' => 'Matières & Disciplines',                'is_active_by_default' => true],
            ['key' => 'canteen',                    'label' => 'Cantine Scolaire',                      'is_active_by_default' => false],
            ['key' => 'transport',                  'label' => 'Transport Scolaire',                    'is_active_by_default' => true],
            ['key' => 'library',                    'label' => 'Bibliothèque',                          'is_active_by_default' => false],
            ['key' => 'reporting',                  'label' => 'Rapports & Statistiques',               'is_active_by_default' => true],
            ['key' => 'roles_accounts',             'label' => 'Créer des comptes',                     'is_active_by_default' => true],
            ['key' => 'settings',                   'label' => 'Paramètres Généraux',                   'is_active_by_default' => true],
            ['key' => 'activity_logs',              'label' => 'Journaux d\'activité',                  'is_active_by_default' => true],
            ['key' => 'affiliated_establishments',  'label' => 'Établissements affiliés',               'is_active_by_default' => true],
        ];

        foreach ($modules as $module) {
            DB::table('modules')->updateOrInsert(
                ['key' => $module['key']],
                array_merge($module, ['updated_at' => now()], DB::table('modules')->where('key', $module['key'])->exists() ? [] : ['created_at' => now()])
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('modules')->whereNotIn('key', [
            'students', 'payments', 'academic_years', 'transport', 'canteen', 'library', 'reporting',
        ])->delete();
    }
};