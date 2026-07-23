<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
             $table->id();
            // Liaison avec l'année scolaire (comme dans votre modèle Classe)
            $table->foreignId('academic_year_id')->constrained('academic_years')->onDelete('cascade');
            
            // Type de période : 'trimestre' ou 'semestre'
            $table->enum('type', ['trimestre', 'semestre'])->default('trimestre');
            
            // Nom de la période (ex: "1er Trimestre", "2ème Trimestre", "1er Semestre")
            $table->string('name');
            
            // Code pour les calculs ou exports (ex: "T1", "T2", "S1")
            $table->string('code');
            
            // Début et fin de la période (utile pour restreindre la saisie des notes ou absences)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            
            // Détermine si c'est la période en cours d'évaluation
            $table->boolean('is_active')->default(false);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
