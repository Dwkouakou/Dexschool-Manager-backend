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
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')
            ->constrained()
            ->cascadeOnDelete();

            $table->string('name', 100);

            $table->string('code', 20);

            $table->unsignedTinyInteger('order')
            ->default(0);

            $table->boolean('is_active')
            ->default(true);


            $table->unique(['cycle_id', 'code']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('levels');
    }
};
