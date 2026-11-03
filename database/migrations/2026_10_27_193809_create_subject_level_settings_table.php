<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paramètres d'une matière pour un niveau donné :
     *  - coefficient (défaut du niveau, NULL = non défini)
     *  - excluded_from_report : matière retirée du bulletin (et donc des
     *    disciplines enseignées) pour ce niveau.
     */
    public function up(): void
    {
        Schema::create('subject_level_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('levels')->cascadeOnDelete();
            $table->decimal('coefficient', 5, 2)->nullable();
            $table->boolean('excluded_from_report')->default(false);
            $table->timestamps();

            $table->unique(['establishment_id', 'subject_id', 'level_id'], 'uniq_subject_level_setting');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_level_settings');
    }
};