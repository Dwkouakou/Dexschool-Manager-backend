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
        Schema::create('transport_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            
            $table->string('name', 150); // ex: Ligne 1 - Yamoussoukro Centre / Morofé
            $table->string('departure_point', 150); // Point de départ
            $table->string('arrival_point', 150); // Point d'arrivée
            $table->text('stops_circuit')->nullable(); // Arrêts saisis manuellement (ex: Dioulakro, Habitat, 220 Logements)
            
            $table->bigInteger('monthly_fee')->default(0); // Forfait mensuel strict en FCFA
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transport_routes');
    }
};
