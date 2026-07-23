<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le matricule employé (ex: EMP-2026-00001) sera désormais préfixé par
     * le code de l'établissement (ex: EMP-MFO-2026-00001). Cette migration
     * fait passer la contrainte d'unicité de globale à scopée par
     * établissement, pour permettre à deux écoles d'avoir chacune leur
     * propre séquence sans collision.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Supprime l'ancienne contrainte unique globale sur matricule
            $table->dropUnique('employees_matricule_unique');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Nouvelle contrainte : unique par établissement
            $table->unique(['establishment_id', 'matricule'], 'employees_estab_matricule_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_estab_matricule_unique');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unique('matricule', 'employees_matricule_unique');
        });
    }
};