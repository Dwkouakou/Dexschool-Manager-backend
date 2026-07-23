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
        Schema::create('student_parents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('student_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('type', [
                'father',
                'mother',
                'guardian'
            ]);

            $table->string('last_name', 100);

            $table->string('first_name', 100);

            $table->string('phone', 30);

            $table->string('phone_alt', 30)
                ->nullable();

            $table->string('email')
                ->nullable();

            $table->string('profession', 150)
                ->nullable();

            $table->string('employer', 150)
                ->nullable();

            // quartier
            $table->string('district', 150)
                ->nullable();

            $table->text('address')
                ->nullable();

            // photo parent
            $table->string('photo')
                ->nullable();

            $table->boolean('is_emergency_contact')
                ->default(false);

            $table->boolean('is_main_contact')
                ->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_parents');
    }
};
