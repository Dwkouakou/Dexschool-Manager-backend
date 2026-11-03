<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - timetable_entries : une case de l'emploi du temps d'une classe (jour + plage horaire → matière [+ salle])
     * - class_timetables  : état de publication de l'emploi du temps d'une classe (visible ou non par les profs)
     */
    public function up(): void
    {
        if (!Schema::hasTable('timetable_entries')) {
            Schema::create('timetable_entries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('classe_id')->constrained('classes')->cascadeOnDelete();
                $table->foreignId('time_slot_id')->constrained('time_slots')->cascadeOnDelete();
                $table->unsignedTinyInteger('day');                 // 1 = lundi … 7 = dimanche
                $table->foreignId('subject_id')->constrained('subjects')->cascadeOnDelete();
                $table->unsignedBigInteger('room_id')->nullable();  // réservé au futur « planning salle »
                $table->timestamps();

                $table->unique(['classe_id', 'time_slot_id', 'day'], 'timetable_class_slot_day_unique');
                $table->index(['establishment_id', 'academic_year_id']);
            });
        }

        if (!Schema::hasTable('class_timetables')) {
            Schema::create('class_timetables', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();
                $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
                $table->foreignId('classe_id')->unique()->constrained('classes')->cascadeOnDelete();
                $table->boolean('is_published')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_timetables');
        Schema::dropIfExists('timetable_entries');
    }
};