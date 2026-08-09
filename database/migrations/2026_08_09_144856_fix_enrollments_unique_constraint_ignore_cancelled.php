<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 0. IMPORTANT : la contrainte unique qu'on va supprimer sert aussi
        // de support à la clé étrangère "student_id" (MySQL exige qu'un
        // index couvre toute colonne référencée par une FK). On crée donc
        // D'ABORD un index simple sur student_id seul, pour que la FK
        // garde un support valide une fois l'ancienne contrainte retirée.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->index('student_id', 'enrollments_student_id_index');
        });

        // 1. Retire l'ancienne contrainte unique STRICTE (student_id, academic_year_id)
        // — elle bloquait à tort toute nouvelle tentative d'inscription après une
        // annulation, car elle ne tenait absolument aucun compte du statut de
        // l'inscription : une ligne "cancelled" comptait exactement comme une
        // ligne active pour cette contrainte.
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_student_id_academic_year_id_unique');
        });

        // 2. Ajoute une colonne CALCULÉE : NULL si l'inscription est annulée,
        // sinon égale à academic_year_id. MySQL autorise plusieurs lignes à
        // NULL sur un index unique — donc autant de tentatives ANNULÉES que
        // l'on veut peuvent coexister pour le même élève/année, mais UNE
        // SEULE ligne active (non annulée) reste possible à la fois.
        DB::statement("
            ALTER TABLE enrollments
            ADD COLUMN active_academic_year_id BIGINT UNSIGNED
            GENERATED ALWAYS AS (CASE WHEN status = 'cancelled' THEN NULL ELSE academic_year_id END) STORED
        ");

        // 3. Nouvelle contrainte unique, basée sur cette colonne calculée —
        // reproduit exactement la logique déjà appliquée côté PHP
        // (EnrollmentController::store()), mais cette fois garantie aussi
        // au niveau base de données, dernier rempart contre toute
        // incohérence (notamment en cas d'accès concurrent).
        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_id', 'active_academic_year_id'], 'enrollments_active_year_unique');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_active_year_unique');
            $table->dropColumn('active_academic_year_id');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->unique(['student_id', 'academic_year_id'], 'enrollments_student_id_academic_year_id_unique');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropIndex('enrollments_student_id_index');
        });
    }
};