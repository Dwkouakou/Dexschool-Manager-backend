<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * ─── CORRECTIF SÉCURITÉ MULTI-TENANT ───
     * Les modèles Book, BookCategory et BookCopy utilisent le trait
     * BelongsToEstablishment (global scope filtrant automatiquement sur
     * establishment_id), mais leurs tables n'ont jamais reçu cette colonne
     * à la création. Toute requête plantait donc avec une erreur SQL
     * "Unknown column 'establishment_id'". book_loans avait déjà la
     * colonne (présente dans le $fillable du modèle dès le départ).
     */
    public function up(): void
    {
        Schema::table('book_categories', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained('establishments')
                ->cascadeOnDelete();
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained('establishments')
                ->cascadeOnDelete();
        });

        Schema::table('book_copies', function (Blueprint $table) {
            $table->foreignId('establishment_id')
                ->nullable()
                ->after('id')
                ->constrained('establishments')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_categories', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });

        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });

        Schema::table('book_copies', function (Blueprint $table) {
            $table->dropForeign(['establishment_id']);
            $table->dropColumn('establishment_id');
        });
    }
};