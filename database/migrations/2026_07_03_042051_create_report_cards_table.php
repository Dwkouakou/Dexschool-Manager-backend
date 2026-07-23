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
        Schema::create('report_cards', function (Blueprint $table) {
                  $table->id();
            
            // On lie le bulletin à la moyenne générale de l'élève pour la période (Module 5)
            $table->foreignId('period_average_id')->constrained('period_averages')->onDelete('cascade');
            
            // Référence unique du bulletin (ex: BL-2026-T1-001)
            $table->string('reference')->unique();
            
            // Statut de signature ou de validation finale avant impression
            $table->enum('status', ['draft', 'generated', 'signed', 'printed'])->default('generated');
            
            // Chemin vers le fichier PDF généré s'il est stocké dans le "storage"
            $table->string('file_path')->nullable();
            
            // Suivi de l'impression pour la traçabilité
            $table->integer('print_count')->default(0);
            $table->foreignId('last_printed_by')->nullable()->constrained('employees')->onDelete('set null');
            $table->timestamp('last_printed_at')->nullable();
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('report_cards');
    }
};
