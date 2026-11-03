<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Bonus attribués à un élève pour une matière et une période (bonus moyenne / bonus points). */
    public function up(): void
    {
        if (!Schema::hasTable('grade_bonuses')) {
            Schema::create('grade_bonuses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
                $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
                $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
                $table->decimal('bonus_average', 5, 2)->default(0);   // ajouté à la moyenne
                $table->decimal('bonus_points', 6, 2)->default(0);    // ajouté au total des points pondérés
                $table->timestamps();

                $table->unique(['student_id', 'subject_id', 'period_id'], 'grade_bonuses_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_bonuses');
    }
};