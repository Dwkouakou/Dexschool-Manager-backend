<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Ajoute establishment_id là où il manque ───────────────────
        if (!Schema::hasColumn('meal_types', 'establishment_id')) {
            Schema::table('meal_types', function (Blueprint $table) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            });
        }

        if (!Schema::hasColumn('canteen_products', 'establishment_id')) {
            Schema::table('canteen_products', function (Blueprint $table) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            });
        }

        // ── 2. Rattache les lignes déjà existantes à l'établissement 1 ───
        // (ajuste l'ID si ton établissement de test n'est pas le 1)
        DB::table('meal_types')->whereNull('establishment_id')->update(['establishment_id' => 1]);
        DB::table('canteen_products')->whereNull('establishment_id')->update(['establishment_id' => 1]);

        // ── 3. Scope les contraintes d'unicité par établissement ─────────
        try { Schema::table('meal_types', fn (Blueprint $t) => $t->dropUnique('meal_types_code_unique')); } catch (\Throwable $e) {}
        Schema::table('meal_types', function (Blueprint $table) {
            $table->unique(['establishment_id', 'code'], 'meal_types_estab_code_unique');
        });

        try { Schema::table('canteen_products', fn (Blueprint $t) => $t->dropUnique('canteen_products_name_unique')); } catch (\Throwable $e) {}
        Schema::table('canteen_products', function (Blueprint $table) {
            $table->unique(['establishment_id', 'name'], 'canteen_products_estab_name_unique');
        });
    }

    public function down(): void
    {
        Schema::table('meal_types', function (Blueprint $table) {
            $table->dropUnique('meal_types_estab_code_unique');
            $table->unique('code', 'meal_types_code_unique');
        });
        Schema::table('canteen_products', function (Blueprint $table) {
            $table->dropUnique('canteen_products_estab_name_unique');
            $table->unique('name', 'canteen_products_name_unique');
        });
    }
};