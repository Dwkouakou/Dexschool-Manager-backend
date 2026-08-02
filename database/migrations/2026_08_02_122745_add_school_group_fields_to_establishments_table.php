<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            // Lien optionnel vers l'établissement parent — NULL = établissement
            // classique/indépendant (comportement actuel inchangé). Si rempli,
            // cet établissement est un "enfant" affilié au groupe scolaire.
            $table->foreignId('parent_establishment_id')
                ->nullable()
                ->after('id')
                ->constrained('establishments')
                ->nullOnDelete();

            // Quota d'établissements enfants que CE client a le droit de
            // créer lui-même depuis son propre compte Admin. 0 = école
            // simple classique (comportement par défaut, rien ne change).
            // Ce champ n'a de sens que sur un établissement PARENT.
            $table->unsignedTinyInteger('child_quota')->default(0)->after('parent_establishment_id');

            // Titre du responsable pour les signatures de documents/reçus
            // (ex: "Directeur" pour Primaire/Maternelle, "Proviseur" pour
            // le Secondaire) — indépendant du champ director_name existant.
            $table->string('director_title')->default('Directeur')->after('director_name');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropForeign(['parent_establishment_id']);
            $table->dropColumn(['parent_establishment_id', 'child_quota', 'director_title']);
        });
    }
};