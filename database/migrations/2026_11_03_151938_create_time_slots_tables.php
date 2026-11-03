<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - time_slots        : plages horaires de la journée de cours (par établissement)
     * - schedule_settings : jours de cours de l'établissement (1 = lundi … 7 = dimanche)
     */
    public function up(): void
    {
        if (!Schema::hasTable('time_slots')) {
            Schema::create('time_slots', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
                $table->time('start_time');
                $table->time('end_time');
                $table->enum('type', ['cours', 'recreation', 'apres_midi'])->default('cours');
                $table->timestamps();

                $table->index(['establishment_id', 'start_time']);
            });
        }

        if (!Schema::hasTable('schedule_settings')) {
            Schema::create('schedule_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->unique()->constrained('establishments')->cascadeOnDelete();
                $table->json('days');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_settings');
        Schema::dropIfExists('time_slots');
    }
};