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
        Schema::create('absence_sheets', function (Blueprint $table) {
             $table->id();
            
            // La classe concernée
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            
            // La matière pendant laquelle on fait l'appel
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            
            // Le prof qui fait l'appel (lié à votre table de personnel)
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            
            // Le trimestre ou semestre en cours (créé au Module 1)
            $table->foreignId('period_id')->constrained('periods')->onDelete('cascade');
            
            // Date et heure de l'appel
            $table->date('date');
            $table->string('time_slot')->nullable(); // Ex: "08h-10h" ou "Matin"
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('absence_sheets');
    }
};
