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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            // Lien optionnel avec un compte de connexion à l'application
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            
            $table->string('matricule', 50)->unique(); // EMP-2026-0001
            $table->string('last_name', 100);
            $table->string('first_name', 150);
            $table->enum('gender', ['M', 'F']);
            $table->string('phone', 25);
            $table->string('email', 100)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('photo', 255)->nullable();
            
            // Si c'est un enseignant (optionnel)
            $table->string('specialty', 100)->nullable(); // ex: Histoire-Géo, Physique
            
            $table->date('hire_date'); // Date d'embauche
            $table->enum('status', ['active', 'suspended', 'left'])->default('active');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
