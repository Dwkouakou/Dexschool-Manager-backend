<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── AJOUT : traçabilité réelle de l'appel du réfectoire. Avant ce
     * correctif, storeAttendance() faisait un updateOrCreate qui écrasait
     * silencieusement toute saisie précédente — aucune trace de qui avait
     * pointé quoi, ni si l'appel avait déjà été validé pour ce jour. Une
     * ligne dans cette table = un appel définitivement enregistré et
     * verrouillé pour une date donnée. Son existence bloque toute
     * nouvelle saisie sur ce même jour.
     */
    public function up(): void
    {
        Schema::create('canteen_attendance_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->date('attendance_date');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at');
            $table->timestamps();

            // ─── CORRECTIF : nom de contrainte explicite et raccourci —
            // le nom auto-généré par Laravel dépassait la limite de 64
            // caractères imposée par MySQL pour les identifiants
            // (canteen_attendance_sheets_establishment_id_attendance_date_unique
            // fait 67 caractères), ce qui faisait échouer la migration.
            $table->unique(['establishment_id', 'attendance_date'], 'canteen_att_sheets_estab_date_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('canteen_attendance_sheets');
    }
};