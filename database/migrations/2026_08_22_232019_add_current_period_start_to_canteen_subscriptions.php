<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── CORRECTIF DE FOND : start_date sur canteen_subscriptions est
     * fixé une fois pour toutes à la création et ne bouge JAMAIS, même
     * après un renouvellement (seul end_date avance). Un versement
     * classique fait après un renouvellement utilisait donc start_date
     * comme "début de la période en cours" — ce qui pointait toujours
     * vers la date de création originale, jamais la vraie période
     * active. current_period_start est mis à jour à chaque renouvellement
     * et remplace start_date pour cet usage précis.
     */
    public function up(): void
    {
        Schema::table('canteen_subscriptions', function (Blueprint $table) {
            $table->date('current_period_start')->nullable()->after('start_date');
        });

        // Rattrapage : pour les abonnements existants, la période en
        // cours démarre au minimum à start_date (mieux que NULL — reste
        // imparfait pour ceux ayant déjà été renouvelés avant ce
        // correctif, mais aucune donnée ne permet de reconstituer la
        // vraie date sans risque de deviner).
        DB::statement('UPDATE canteen_subscriptions SET current_period_start = start_date WHERE current_period_start IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canteen_subscriptions', function (Blueprint $table) {
            $table->dropColumn('current_period_start');
        });
    }
};