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
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            
            // Lien avec la feuille d'appel parente
            $table->foreignId('absence_sheet_id')->constrained('absence_sheets')->onDelete('cascade');
            
            // L'élève concerné (ajustez 'students' selon le nom exact de votre table élèves)
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            
            // Statut de l'élève lors de l'appel
            $table->enum('status', ['present', 'absent', 'late', 'excluded'])->default('present');
            
            // Gestion de la justification (gérée par les surveillants)
            $table->boolean('is_justified')->default(false);
            $table->string('justification_reason')->nullable(); // Ex: "Certificat médical"
            $table->foreignId('justified_by')->nullable()->constrained('employees')->onDelete('set null'); // Le surveillant qui a validé
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
