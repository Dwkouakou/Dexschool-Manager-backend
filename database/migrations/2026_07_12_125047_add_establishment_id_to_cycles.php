<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Supprimer l'ancien unique global sur le code
        Schema::table('cycles', function (Blueprint $table) {
            $table->dropUnique('cycles_code_unique');
        });

        // 2. Ajouter establishment_id (nullable d'abord)
        Schema::table('cycles', function (Blueprint $table) {
            if (!Schema::hasColumn('cycles', 'establishment_id')) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            }
        });

        // 3. Backfill vers MFO-001
        $mfoId = DB::table('establishments')->where('code', 'MFO-001')->value('id');
        if ($mfoId) {
            DB::table('cycles')->whereNull('establishment_id')->update(['establishment_id' => $mfoId]);
        }

        // 4. Nouveau unique scopé par établissement
        Schema::table('cycles', function (Blueprint $table) {
            $table->unique(['establishment_id', 'code'], 'cycles_establishment_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cycles', function (Blueprint $table) {
            $table->dropUnique('cycles_establishment_code_unique');
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
            $table->unique('code', 'cycles_code_unique');
        });
    }
};