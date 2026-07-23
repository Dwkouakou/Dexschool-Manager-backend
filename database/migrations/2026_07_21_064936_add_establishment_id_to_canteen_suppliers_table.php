<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('canteen_suppliers', 'establishment_id')) {
            Schema::table('canteen_suppliers', function (Blueprint $table) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            });
        }

        DB::table('canteen_suppliers')->whereNull('establishment_id')->update(['establishment_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('canteen_suppliers', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};