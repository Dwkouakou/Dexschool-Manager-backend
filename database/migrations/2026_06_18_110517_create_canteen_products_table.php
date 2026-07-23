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
        Schema::create('canteen_products', function (Blueprint $table) {
           $table->id();
            $table->string('name', 100);
            $table->string('unit', 30); // Sac (50kg), Litre, Carton...
            $table->integer('current_stock')->default(0); 
            $table->integer('alert_threshold')->default(5); 
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_products');
    }
};
