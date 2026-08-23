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
     * ─── AJOUT : la table canteen_payments existait déjà (versements et
     * renouvellements) mais n'était jamais utilisée par le contrôleur —
     * les versements étaient enregistrés uniquement en incrémentant
     * amount_paid sur l'abonnement, sans aucune ligne d'historique. On
     * ajoute establishment_id EN DIRECT (pas de scope indirect via
     * whereHas comme CanteenAttendance / CanteenStockMovement — table
     * financière, on veut la solidité et la rapidité d'une vraie colonne)
     * et receipt_number pour la numérotation des reçus, format
     * CT-{préfixe établissement}-{année}-{numéro séquentiel}.
     */
    public function up(): void
    {
        Schema::table('canteen_payments', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained('establishments')
                ->cascadeOnDelete();

            $table->string('receipt_number', 50)->nullable()->unique()->after('type');
        });

        // ─── CORRECTIF SÉCURITÉ MULTI-TENANT : jamais d'ID en dur ───
        // canteen_payments a une relation directe vers canteen_subscriptions,
        // qui possède déjà un vrai establishment_id correct. On DÉRIVE la
        // bonne valeur depuis l'abonnement lié plutôt que de deviner un
        // numéro fixe — ça reste juste même en production, avec
        // n'importe quel nombre d'établissements déjà existants. En
        // pratique cette table n'a jamais été alimentée avant ce
        // correctif (aucune ligne existante à l'heure où ce fichier est
        // écrit), donc cette requête ne devrait rien affecter — mais elle
        // reste correcte si jamais des lignes orphelines apparaissaient.
        DB::statement('
            UPDATE canteen_payments cp
            JOIN canteen_subscriptions cs ON cs.id = cp.canteen_subscription_id
            SET cp.establishment_id = cs.establishment_id
            WHERE cp.establishment_id IS NULL
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('canteen_payments', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn(['establishment_id', 'receipt_number']);
        });
    }
};