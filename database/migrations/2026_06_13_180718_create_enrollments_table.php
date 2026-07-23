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
        Schema::create('enrollments', function (Blueprint $table) {
              $table->id();
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('enrollment_number')->unique();
            $table->enum('type', ['new', 're_enrollment']);
            $table->enum('status', ['pending', 'validated', 'cancelled'])->default('pending');
            $table->date('enrollment_date');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Unicité : un élève ne peut s'inscrire qu'une fois par année scolaire
            $table->unique(['student_id', 'academic_year_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
