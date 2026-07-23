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
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('matricule', 50)->unique();
            $table->string('last_name', 100);
            $table->string('first_name', 150);
            $table->enum('gender', ['M', 'F']);
            $table->date('birth_date');
            $table->string('birth_place', 100);
            $table->string('phone', 25)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('address', 255)->nullable();
            
            // Clés étrangères
            $table->foreignId('class_id')->constrained('classes')->onDelete('restrict');
            $table->foreignId('academic_year_id')->constrained('academic_years')->onDelete('restrict');
            
            $table->string('photo', 255)->nullable();
            $table->boolean('is_active')->default(true);
            
            // Traçabilité (optionnel mais recommandé)
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
