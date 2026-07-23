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
        Schema::create('canteen_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 150);
            $table->string('contact_name', 100)->nullable();
            $table->string('phone', 25);
            $table->string('address', 255)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_suppliers');
    }
};
