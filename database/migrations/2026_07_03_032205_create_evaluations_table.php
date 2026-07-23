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
        Schema::create('evaluations', function (Blueprint $table) {
            $table->id();
            
            // La classe, la matière et le prof concernés
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            
            // Rattachement au trimestre ou semestre actif (Module 1)
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade');
            
            // Infos sur le devoir
            $table->string('title'); // Ex: "Interrogation Écrite n°1", "Devoir de niveau"
            $table->enum('type', ['interrogation', 'devoir', 'examen'])->default('devoir');
            $table->date('date');
            
            // Bareme de notation (par défaut sur 20)
            $table->integer('max_score')->default(20);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluations');
    }
};
