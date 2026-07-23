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
        Schema::create('canteen_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained('canteen_subscriptions')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->boolean('present')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'attendance_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_attendances');
    }
};
