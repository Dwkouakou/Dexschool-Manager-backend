<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Grille tarifaire des inscriptions / réinscriptions :
     * un tarif par (établissement, niveau, statut d'affectation).
     *  - affecte      → seuls les frais d'inscription sont utilisés
     *  - non_affecte  → frais d'inscription + scolarité
     */
    public function up(): void
    {
        Schema::create('establishment_level_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('levels')->cascadeOnDelete();
            $table->string('assignment_status', 20); // affecte | non_affecte
            $table->unsignedBigInteger('registration_fee')->default(0);
            $table->unsignedBigInteger('tuition_fee')->default(0);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'level_id', 'assignment_status'], 'uniq_estab_level_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_level_fees');
    }
};