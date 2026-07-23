<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique('positions_name_unique');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->unique(['establishment_id', 'name'], 'positions_estab_name_unique');
        });

        // On corrige aussi le slug par précaution, même piège probable
        Schema::table('positions', function (Blueprint $table) {
            try { $table->dropUnique('positions_slug_unique'); } catch (\Throwable $e) {}
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->unique(['establishment_id', 'slug'], 'positions_estab_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique('positions_estab_name_unique');
            $table->dropUnique('positions_estab_slug_unique');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->unique('name', 'positions_name_unique');
            $table->unique('slug', 'positions_slug_unique');
        });
    }
};