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
        Schema::create('grades', function (Blueprint $table) {
             $table->id();
            
            // Lien avec l'évaluation parente
            $table->foreignId('evaluation_id')->constrained('evaluations')->onDelete('cascade');
            
            // L'élève qui reçoit la note
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            
            // La note stockée en décimal (ex: 15.50) pour gérer la précision des calculs
            $table->decimal('score', 4, 2)->nullable(); // nullable si l'élève était absent
            
            // Cas particulier : l'élève a triché ou était absent non justifié
            $table->boolean('is_absent')->default(false);
            
            // Petit commentaire du prof sur la copie de cet élève
            $table->string('teacher_comment')->nullable(); // Ex: "En hausse", "Attention aux fautes"
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('grades');
    }
};
