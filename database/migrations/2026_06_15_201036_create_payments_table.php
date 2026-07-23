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
        Schema::create('payments', function (Blueprint $table) {
              $table->id();
            // Lien avec l'inscription annuelle de l'élève
            $table->foreignId('enrollment_id')->constrained()->cascadeOnDelete();
            
            // Numéro de reçu unique (ex: REC-2026-00001)
            $table->string('receipt_number')->unique();
            
            // Détails financiers du versement (FCFA strict)
            $table->bigInteger('amount_paid'); 
            $table->date('payment_date');
            $table->enum('payment_method', ['cash', 'wave', 'orange_money', 'mtn_money', 'moov_money', 'virement']);
            
            // Référence de la transaction (Utile pour Wave/OM ou les chèques)
            $table->string('transaction_reference')->nullable();
            
            $table->text('notes')->nullable();
            
            // Traçabilité de l'agent de caisse
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
