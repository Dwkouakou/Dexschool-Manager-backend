<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'establishment_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};