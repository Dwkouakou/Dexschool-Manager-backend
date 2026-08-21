<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ─── CORRECTIF : ces 3 tables utilisent le trait
        // BelongsToEstablishment (filtrage automatique par établissement),
        // mais la colonne elle-même n'avait jamais été ajoutée en base —
        // chaque requête plantait dès qu'elle essayait de filtrer dessus.
        // Nullable pour l'instant : les lignes déjà existantes n'ont pas
        // encore de valeur, il faudra les rattraper manuellement une fois
        // la migration passée (voir instructions).

        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignId('establishment_id')->nullable()->after('id')
                ->constrained('establishments')->cascadeOnDelete();
        });

        Schema::table('transport_routes', function (Blueprint $table) {
            $table->foreignId('establishment_id')->nullable()->after('id')
                ->constrained('establishments')->cascadeOnDelete();
        });

        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->foreignId('establishment_id')->nullable()->after('id')
                ->constrained('establishments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('establishment_id');
        });

        Schema::table('transport_routes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('establishment_id');
        });

        Schema::table('vehicle_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('establishment_id');
        });
    }
};