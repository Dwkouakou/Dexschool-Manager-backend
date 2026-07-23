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
        Schema::create('student_documents', function (Blueprint $table) {
            $table->id();

            // Clé étrangère liée à la table students
            $table->foreignId('student_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // Types pris en compte : acte_naissance, certificat_medical, photo_identite, etc.
            $table->string('document_type', 50);
            
            // Intitulé personnalisé donné par l'utilisateur
            $table->string('title', 255);

            // Informations de stockage du fichier
            $table->string('file_path'); // Chemin relatif (ex: documents/student_1/filename.pdf)
            $table->string('file_name')->nullable(); // Nom d'origine du fichier
            $table->string('mime_type')->nullable(); // Type MIME (ex: application/pdf)
            $table->unsignedBigInteger('file_size')->nullable(); // Taille en octets

            // Champs optionnels ou futurs
            $table->text('description')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->boolean('is_required')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_documents');
    }
};
