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
        Schema::create('canteen_subscriptions', function (Blueprint $table) {
           $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_type_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            
            // Comptabilité FCFA
            $table->bigInteger('total_amount')->default(0); 
            $table->bigInteger('amount_paid')->default(0);  
            
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->unique(['student_id', 'academic_year_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_subscriptions');
    }
};
