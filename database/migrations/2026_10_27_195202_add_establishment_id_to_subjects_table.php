<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le modèle Subject utilise BelongsToEstablishment mais la table n'avait
     * pas de colonne establishment_id → erreur SQL « Unknown column ».
     *  1. ajoute establishment_id ;
     *  2. rattache les matières existantes si l'application n'a qu'UN établissement ;
     *  3. remplace l'unicité globale du code par (establishment_id, code).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('subjects', 'establishment_id')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->foreignId('establishment_id')->nullable()->after('id')
                    ->constrained('establishments')->cascadeOnDelete();
            });
        }

        // Matières déjà présentes : rattachées seulement s'il n'y a pas d'ambiguïté.
        if (DB::table('establishments')->count() === 1) {
            DB::table('subjects')->whereNull('establishment_id')
                ->update(['establishment_id' => DB::table('establishments')->value('id')]);
        }

        $indexes = collect(Schema::getIndexes('subjects'))->pluck('name');

        Schema::table('subjects', function (Blueprint $table) use ($indexes) {
            if ($indexes->contains('subjects_code_unique')) {
                $table->dropUnique('subjects_code_unique');
            }
            if (!$indexes->contains('subjects_establishment_code_unique')) {
                $table->unique(['establishment_id', 'code'], 'subjects_establishment_code_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique('subjects_establishment_code_unique');
            $table->unique('code', 'subjects_code_unique');
        });
        // La colonne establishment_id est conservée volontairement.
    }
};