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
        Schema::create('period_averages', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade'); // Trimestre ou Semestre
            
            // Moyenne générale (Somme des moyennes pondérées par les coeffs / Somme des coeffs)
            $table->decimal('general_average', 4, 2);
            
            // Rang général dans la classe (ex: 1 pour le premier)
            $table->integer('rank')->nullable();
            
            // Stats de la classe pour situer l'élève sur le bulletin
            $table->decimal('class_highest_average', 4, 2)->nullable(); // Plus forte moyenne
            $table->decimal('class_lowest_average', 4, 2)->nullable();  // Plus faible moyenne
            $table->decimal('class_average', 4, 2)->nullable();         // Moyenne de la classe
            
            // Décisions du conseil de classe
            $table->string('council_appreciation')->nullable(); // Ex: "Félicitations", "Doit redoubler d'efforts"
            $table->boolean('is_validated')->default(false); // Validé définitivement par le DG/Directeur des études
            
            $table->timestamps();
            
            // Clé unique pour éviter les doublons
            $table->unique(['student_id', 'period_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('period_averages');
    }
};
