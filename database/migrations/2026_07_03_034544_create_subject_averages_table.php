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
        Schema::create('subject_averages', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade'); // Trimestre ou Semestre
            
            // La moyenne calculée (ex: 14.25)
            $table->decimal('average', 4, 2);
            
            // Le rang de l'élève spécifiquement dans cette matière et cette classe (ex: 3ème / 35)
            $table->integer('rank')->nullable();
            
            // Appréciation automatique ou manuelle du prof pour cette matière
            $table->string('teacher_appreciation')->nullable();
            
            $table->timestamps();
            
            // Clé unique pour éviter les doublons de calculs pour un élève dans une matière sur une période
            $table->unique(['student_id', 'subject_id', 'period_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subject_averages');
    }
};
