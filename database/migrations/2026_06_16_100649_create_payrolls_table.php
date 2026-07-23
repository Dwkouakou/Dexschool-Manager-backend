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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('employee_contrats')->cascadeOnDelete();
            
            $table->string('payroll_number')->unique(); // PAY-202606-001 (AnnéeMois-Compteur)
            $table->string('salary_month', 7); // Format: YYYY-MM (ex: 2026-06)
            
            // Calculs financiers stricts en FCFA
            $table->bigInteger('base_salary');
            $table->bigInteger('allowances')->default(0); // Primes
            $table->bigInteger('deductions')->default(0); // Retenues (avances, retards)
            $table->bigInteger('net_salary'); // Calculé : Base + Primes - Retenues
            
            $table->date('payment_date');
            $table->enum('payment_method', ['cash', 'virement', 'cheque', 'mobile_money']);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payrolls');
    }
};
