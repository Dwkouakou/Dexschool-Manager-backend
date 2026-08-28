<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : catalogue central de tous les modules de l'application.
     * Ajouter un nouveau module à l'avenir = ajouter UNE ligne ici (via
     * migration/seeder au moment de livrer ce module) — l'interface
     * SuperAdmin le fait apparaître automatiquement dans les toggles,
     * sans aucun code frontend à modifier.
     */
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique(); // ex: 'canteen', 'transport', 'library'
            $table->string('label', 100);         // ex: 'Cantine Scolaire'
            $table->text('description')->nullable();
            $table->boolean('is_active_by_default')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};