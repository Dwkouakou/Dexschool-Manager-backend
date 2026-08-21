<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transport_payments', function (Blueprint $table) {
            $table->id();

            // Scoping multi-tenant, comme les autres modèles Transport
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();

            $table->foreignId('subscription_id')->constrained('transport_subscriptions')->cascadeOnDelete();

            $table->string('receipt_number')->unique(); // ex: TR-CVSA26-2026-00001
            $table->integer('amount_paid');
            $table->date('payment_date');
            $table->string('payment_method')->default('cash');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transport_payments');
    }
};