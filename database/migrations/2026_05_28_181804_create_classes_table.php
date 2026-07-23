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
        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')
            ->constrained()
            ->cascadeOnDelete();

            $table->foreignId('academic_year_id')
            ->constrained()
            ->cascadeOnDelete();

            $table->string('name', 100);

            $table->string('code', 20);

            $table->unsignedTinyInteger('capacity');

            $table->string('classroom', 100)
            ->nullable();

            $table->foreignId('main_teacher_id')
            ->nullable()
            ->default(null);

            $table->boolean('is_active')
            ->default(true);


            $table->unique([
            'level_id',
            'academic_year_id',
            'code'
            ]);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('classes');
    }
};
