<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les colonnes nécessaires pour scoper les rôles Spatie
     * par établissement + gérer les 3 niveaux de protection.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // NULL = rôle système global (avant migration) ; renseigné = rôle propre à un établissement
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained('establishments')
                ->cascadeOnDelete();

            // Identifiant technique stable (ex: 'admin', 'directeur') pour retrouver un rôle
            // même si l'école renomme son libellé (name)
            $table->string('slug', 60)->nullable()->after('guard_name');

            // true = rôle livré par DexSchool à la création de l'école
            $table->boolean('is_system')->default(false)->after('slug');

            // true = rôle Admin — aucune modification/suppression autorisée
            $table->boolean('is_locked')->default(false)->after('is_system');

            // Description optionnelle affichée dans l'UI
            $table->string('description', 255)->nullable()->after('is_locked');

            $table->index(['establishment_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropIndex(['establishment_id', 'slug']);
            $table->dropColumn(['establishment_id', 'slug', 'is_system', 'is_locked', 'description']);
        });
    }
};  