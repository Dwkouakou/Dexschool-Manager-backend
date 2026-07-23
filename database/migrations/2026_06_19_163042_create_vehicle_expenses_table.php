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
        Schema::create('vehicle_expenses', function (Blueprint $table) {
           $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            
            $table->string('title', 150); // ex: Vidange moteur ou Achat Gasoil
            $table->bigInteger('amount'); // Montant strict en FCFA
            $table->enum('category', ['fuel', 'washing', 'repair', 'insurance', 'salary', 'other']);
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_expenses');
    }
};
