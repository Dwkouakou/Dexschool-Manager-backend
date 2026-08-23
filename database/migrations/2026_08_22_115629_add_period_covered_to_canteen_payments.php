<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : distingue "quand l'argent a été encaissé" (payment_date)
     * de "quelle période de scolarité ce versement couvre"
     * (period_covered → period_end). Sans cette distinction, un parent
     * payant plusieurs mois d'avance en une seule fois (ou plusieurs
     * renouvellements faits le même jour) voit tous ses versements
     * regroupés sous le même mois calendaire dans le Cumul, et le reçu ne
     * peut jamais préciser jusqu'à quand un paiement couvre — seulement
     * "à partir de quand".
     */
    public function up(): void
    {
        Schema::table('canteen_payments', function (Blueprint $table) {
            $table->date('period_covered')->nullable()->after('payment_date'); // Début de la période couverte
            $table->date('period_end')->nullable()->after('period_covered');   // Fin de la période couverte
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canteen_payments', function (Blueprint $table) {
            $table->dropColumn(['period_covered', 'period_end']);
        });
    }
};