<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_financial_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->json('old_values');                       // montants avant correction
            $table->json('new_values');                       // montants après correction
            $table->unsignedBigInteger('paid_at_time')->default(0); // total déjà versé au moment de la correction
            $table->string('reason', 500);
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->useCurrent();
            $table->timestamps();

            $table->index(['establishment_id', 'academic_year_id'], 'efc_estab_year_idx');
            $table->index('enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_financial_corrections');
    }
};