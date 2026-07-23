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
        Schema::create('employee_contrats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            
            $table->enum('contract_type', ['CDI', 'CDD', 'vacation', 'stage']);
            $table->date('start_date');
            $table->date('end_date')->nullable(); // Null si CDI
            
            // Rémunération de base contractuelle en FCFA
            $table->bigInteger('base_salary')->default(0); 
            $table->enum('status', ['active', 'expired', 'terminated'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_contrats');
    }
};
