<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canteen_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('canteen_subscription_id')->constrained('canteen_subscriptions')->cascadeOnDelete();
            $table->integer('amount'); // FCFA, entier
            $table->date('payment_date');
            $table->string('type')->default('payment'); // 'payment' (versement classique) ou 'renewal' (versement lors d'un renouvellement)
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canteen_payments');
    }
};