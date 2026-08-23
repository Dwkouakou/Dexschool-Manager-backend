<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : fige le reste à payer TEL QU'IL ÉTAIT juste après ce
     * versement précis. Le calculer "en direct" depuis l'abonnement au
     * moment de générer le PDF serait faux si d'autres versements ont eu
     * lieu depuis — un ancien reçu réimprimé afficherait alors le reste
     * dû ACTUEL au lieu de celui de l'époque. Même logique que
     * period_covered / period_end.
     */
    public function up(): void
    {
        Schema::table('canteen_payments', function (Blueprint $table) {
            $table->integer('remaining_after')->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canteen_payments', function (Blueprint $table) {
            $table->dropColumn('remaining_after');
        });
    }
};