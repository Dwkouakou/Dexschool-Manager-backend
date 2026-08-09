<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un élève dont l'unique inscription vient d'être annulée n'a, en
        // toute logique, PLUS de classe/année en cours tant qu'il n'est pas
        // réinscrit — ces champs doivent donc pouvoir être vides. Sans ça,
        // impossible de libérer réellement sa place dans la classe après
        // annulation (la vérification de capacité au moment de soumettre
        // une nouvelle inscription se base sur ces mêmes colonnes).
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('class_id')->nullable()->change();
            $table->unsignedBigInteger('academic_year_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->unsignedBigInteger('class_id')->nullable(false)->change();
            $table->unsignedBigInteger('academic_year_id')->nullable(false)->change();
        });
    }
};