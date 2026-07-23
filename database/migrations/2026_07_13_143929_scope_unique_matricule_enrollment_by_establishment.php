<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // students.matricule : global → scopé par établissement
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_matricule_unique');
            $table->unique(['establishment_id', 'matricule'], 'students_estab_matricule_unique');
        });

        // enrollments.enrollment_number : global → scopé via establishment_id
        // ⚠️ enrollments n'a PAS de colonne establishment_id (table enfant).
        // On l'ajoute UNIQUEMENT pour porter la contrainte unique proprement.
        Schema::table('enrollments', function (Blueprint $table) {
            if (!Schema::hasColumn('enrollments', 'establishment_id')) {
                $table->foreignId('establishment_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('establishments')
                    ->cascadeOnDelete();
            }
        });

        // Backfill enrollments.establishment_id depuis le student lié
        \Illuminate\Support\Facades\DB::statement("
            UPDATE enrollments e
            JOIN students s ON s.id = e.student_id
            SET e.establishment_id = s.establishment_id
            WHERE e.establishment_id IS NULL
        ");

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_enrollment_number_unique');
            $table->unique(['establishment_id', 'enrollment_number'], 'enrollments_estab_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique('students_estab_matricule_unique');
            $table->unique('matricule', 'students_matricule_unique');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_estab_number_unique');
            $table->unique('enrollment_number', 'enrollments_enrollment_number_unique');
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};