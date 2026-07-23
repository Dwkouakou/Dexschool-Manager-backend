<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('canteen_expenses', 'establishment_id')) {
            Schema::table('canteen_expenses', function (Blueprint $table) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            });
        }

        // Rattache les lignes déjà existantes à l'établissement 1
        DB::table('canteen_expenses')->whereNull('establishment_id')->update(['establishment_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('canteen_expenses', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};