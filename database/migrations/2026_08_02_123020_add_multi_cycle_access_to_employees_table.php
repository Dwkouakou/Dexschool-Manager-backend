<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Autorise (ou non) l'attribution de rôles à ce personnel dans
            // D'AUTRES établissements du même groupe scolaire (ex: une
            // comptable qui gère Maternelle + Primaire + Secondaire).
            // false par défaut = comportement actuel inchangé, un employé
            // reste cantonné à son établissement d'origine.
            $table->boolean('multi_cycle_access')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('multi_cycle_access');
        });
    }
};