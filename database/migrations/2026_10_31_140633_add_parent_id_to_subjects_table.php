<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sous-matières : une matière peut avoir une matière principale (parent_id), sur 1 seul niveau de profondeur. */
    public function up(): void
    {
        if (!Schema::hasColumn('subjects', 'parent_id')) {
            Schema::table('subjects', function (Blueprint $table) {
                $table->foreignId('parent_id')->nullable()->after('establishment_id')
                    ->constrained('subjects')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};