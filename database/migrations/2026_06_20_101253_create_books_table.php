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
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('book_categories')->nullOnDelete();
            $table->string('title', 150);
            $table->string('author', 100)->nullable();
            $table->string('publisher', 100)->nullable();
            $table->string('isbn', 30)->nullable();
            $table->integer('publication_year')->nullable();
            $table->text('description')->nullable();
            $table->string('cover', 255)->nullable(); // Photo de couverture
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
