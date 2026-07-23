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
        Schema::create('canteen_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->bigInteger('amount'); // Montant entier en FCFA
            $table->date('expense_date');
            $table->enum('category', ['Riz', 'Huile', 'Viande', 'Poisson', 'Légumes', 'Gaz', 'Charbon', 'Eau', 'Entretien', 'Autre']);
            $table->text('description')->nullable();
            $table->string('receipt', 255)->nullable(); 
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_expenses');
    }
};
