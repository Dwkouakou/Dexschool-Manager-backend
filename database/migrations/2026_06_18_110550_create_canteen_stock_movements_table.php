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
        Schema::create('canteen_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canteen_product_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['in', 'out']); // in = Entrée, out = Sortie Cuisine
            $table->integer('quantity');
            $table->string('reason', 150); 
            $table->date('movement_date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_stock_movements');
    }
};
