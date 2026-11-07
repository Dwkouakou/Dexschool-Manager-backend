<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Restriction de saisie des professeurs + clôture d'une période (verrouille aussi l'administrateur). */
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            if (!Schema::hasColumn('periods', 'teachers_locked')) {
                $table->boolean('teachers_locked')->default(false);
                $table->timestamp('teachers_locked_at')->nullable();
            }
            if (!Schema::hasColumn('periods', 'is_closed')) {
                $table->boolean('is_closed')->default(false);
                $table->timestamp('closed_at')->nullable();
                $table->unsignedBigInteger('closed_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            $table->dropColumn(['teachers_locked', 'teachers_locked_at', 'is_closed', 'closed_at', 'closed_by']);
        });
    }
};