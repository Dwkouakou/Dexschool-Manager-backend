<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'students',
        'classes',
        'canteen_subscriptions',
        'transport_subscriptions',
        'book_loans',
    ];

    public function up(): void
    {
        // 1. Ajouter la colonne (nullable d'abord, pour ne pas casser l'existant)
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && !Schema::hasColumn($tableName, 'establishment_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('establishment_id')
                        ->nullable()
                        ->after('id')
                        ->constrained('establishments')
                        ->cascadeOnDelete();
                });
            }
        }

        // 2. Backfill : rattacher les données existantes à MFO-001
        $mfoId = DB::table('establishments')->where('code', 'MFO-001')->value('id');

        if ($mfoId) {
            foreach ($this->tables as $tableName) {
                if (Schema::hasTable($tableName)) {
                    DB::table($tableName)
                        ->whereNull('establishment_id')
                        ->update(['establishment_id' => $mfoId]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'establishment_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['establishment_id']);
                    $table->dropColumn('establishment_id');
                });
            }
        }
    }
};