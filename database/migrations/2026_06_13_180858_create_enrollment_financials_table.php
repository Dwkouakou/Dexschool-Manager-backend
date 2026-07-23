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
        Schema::create('enrollment_financials', function (Blueprint $table) {
         $table->id();
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            
            // Montants entiers sans virgule adaptés pour le Franc CFA
            $table->bigInteger('registration_fee')->default(0); 
            $table->bigInteger('tuition_fee')->default(0);      
            $table->bigInteger('annex_fee')->default(0);        
            $table->bigInteger('discount_amount')->default(0);  
            $table->bigInteger('total_due')->default(0);        
            $table->bigInteger('initial_payment')->default(0); 
            
            $table->enum('payment_method', ['cash', 'online', 'none'])->default('none');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('enrollment_financials');
    }
};
