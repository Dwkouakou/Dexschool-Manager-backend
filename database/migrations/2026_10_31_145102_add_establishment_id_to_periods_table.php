<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le modèle Period utilise BelongsToEstablishment : la table doit avoir establishment_id.
     * Les lignes existantes héritent de l'établissement de leur année scolaire (fiable, pas de supposition).
     * Ajoute aussi l'unicité (année, code) pour interdire les doublons T1/T2/S1… même en cas de double clic.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('periods', 'establishment_id')) {
            Schema::table('periods', function (Blueprint $table) {
                $table->foreignId('establishment_id')->nullable()->after('id')
                    ->constrained('establishments')->cascadeOnDelete();
            });
        }

        DB::statement('UPDATE periods p JOIN academic_years y ON y.id = p.academic_year_id
                       SET p.establishment_id = y.establishment_id WHERE p.establishment_id IS NULL');

        $indexes = collect(Schema::getIndexes('periods'))->pluck('name');
        $hasDup  = DB::table('periods')->select('academic_year_id', 'code')
            ->groupBy('academic_year_id', 'code')->havingRaw('COUNT(*) > 1')->exists();

        if (!$indexes->contains('periods_year_code_unique') && !$hasDup) {
            Schema::table('periods', fn (Blueprint $t) => $t->unique(['academic_year_id', 'code'], 'periods_year_code_unique'));
        }
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            if (collect(Schema::getIndexes('periods'))->pluck('name')->contains('periods_year_code_unique')) {
                $table->dropUnique('periods_year_code_unique');
            }
        });
    }
};