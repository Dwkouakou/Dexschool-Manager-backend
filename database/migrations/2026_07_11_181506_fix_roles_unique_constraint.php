<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modifie la contrainte d'unicité de la table `roles` de Spatie
     * pour qu'elle inclue `establishment_id`.
     *
     * AVANT : unique(name, guard_name)
     *   → Impossible d'avoir "Administrateur" pour plusieurs écoles
     *
     * APRES : unique(name, guard_name, establishment_id)
     *   → Chaque école peut avoir son propre "Administrateur"
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // 1. Supprimer l'ancienne contrainte unique
            $table->dropUnique('roles_name_guard_name_unique');
        });

        Schema::table('roles', function (Blueprint $table) {
            // 2. Créer la nouvelle contrainte qui inclut establishment_id
            $table->unique(['name', 'guard_name', 'establishment_id'], 'roles_name_guard_establishment_unique');
        });
    }

    /**
     * Rollback : remet l'ancienne contrainte
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_guard_establishment_unique');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });
    }
};