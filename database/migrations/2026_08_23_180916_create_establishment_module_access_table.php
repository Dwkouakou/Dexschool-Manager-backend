<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : table d'EXCEPTIONS, pas une ligne par établissement ×
     * module. Une ligne ici = "pour CET établissement, ce module est
     * forcé à tel état, peu importe le défaut du catalogue". L'absence de
     * ligne = on applique le défaut de la table modules. Reste volontai-
     * rement simple pour pouvoir, plus tard, brancher un système de plans
     * d'abonnement sans tout reconstruire (le "défaut" pourrait alors
     * dépendre du plan, la logique de résolution resterait la même).
     */
    public function up(): void
    {
        Schema::create('establishment_module_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('module_id')->constrained('modules')->cascadeOnDelete();
            $table->boolean('is_enabled');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['establishment_id', 'module_id'], 'estab_module_access_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('establishment_module_access');
    }
};