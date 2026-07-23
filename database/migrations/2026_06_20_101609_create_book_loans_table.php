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
        Schema::create('book_loans', function (Blueprint $table) {
             $table->id();
            $table->foreignId('book_copy_id')->constrained('book_copies')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            
            // Émetteur double (Polymorphisme propre ou doubles colonnes optionnelles)
            $table->foreignId('student_id')->nullable()->constrained('students')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete(); // Ouvert au Personnel RH
            
            $table->date('loan_date');
            $table->date('expected_return_date'); // Date limite de retour
            $table->date('returned_at')->nullable(); // Date réelle de restitution
            
            $table->enum('status', ['borrowed', 'returned', 'late', 'lost'])->default('borrowed');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); // Traçabilité du bibliothécaire
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_loans');
    }
};
