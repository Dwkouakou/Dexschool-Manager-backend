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
        Schema::create('classroom_subject_teachers', function (Blueprint $table) {
            $table->id();
            
            // ID de l'enseignant (lié à votre table de personnel administratif/technique existante)
            $table->foreignId('employee_id')->constrained('employees')->onDelete('cascade');
            
            // ID de la classe (lié à votre table 'classes')
            $table->foreignId('classe_id')->constrained('classes')->onDelete('cascade');
            
            // ID de la matière (lié à votre table 'subjects')
            $table->foreignId('subject_id')->constrained('subjects')->onDelete('cascade');
            
            // Le coefficient spécifique pour cette matière dans cette classe précise
            $table->integer('coefficient')->default(1);
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classroom_subject_teachers');
    }
};
