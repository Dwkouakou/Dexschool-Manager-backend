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
        Schema::create('vehicles', function (Blueprint $table) {
             $table->id();
            // Liaison optionnelle avec l'employé au poste de Chauffeur
            $table->foreignId('driver_id')->nullable()->constrained('employees')->nullOnDelete();
            
            $table->string('name', 100); // ex: Car de ramassage N°1
            $table->string('registration_number', 50)->unique(); // Matricule (ex: 2450GZ01)
            $table->string('brand', 100)->nullable(); // Toyota, Isuzu...
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('capacity'); // Nombre de places assises
            $table->date('purchase_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
