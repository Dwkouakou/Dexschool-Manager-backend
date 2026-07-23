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
        Schema::create('academic_years', function (Blueprint $table) {
            // $table->id();
            // $table->string('name', 100);
            // $table->date('start_date');
            // $table->date('end_date');
            // $table->boolean('is_active')->default(false);
            // $table->boolean('is_archived')->default(false);
            // $table->foreignId('created_by')
            //     ->nullable()
            //     ->constrained('users')
            //     ->nullOnDelete();
            // $table->foreignId('updated_by')
            // ->nullable() 
            // ->constrained('users')
            // ->nullOnDelete();
            // $table->timestamps();

            
            $table->id();
            // Liaison indispensable
            $table->foreignId('establishment_id')->constrained('establishments')->onDelete('cascade');
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_active')->default(false);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('academic_years');
    }
};
