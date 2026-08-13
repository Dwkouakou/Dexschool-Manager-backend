<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishment_activity_logs', function (Blueprint $table) {
            $table->id();

            // ─── Scoping multi-tenant : CRITIQUE ───
            // Toujours rempli via current_establishment_id() au moment de
            // l'action, jamais via $user->establishment_id directement (même
            // piège que partout ailleurs dans l'app : un Admin qui consulte
            // un établissement enfant du groupe doit logger sur CET
            // établissement, pas sur son établissement d'origine).
            $table->foreignId('establishment_id')->constrained('establishments')->cascadeOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name'); // Snapshot du nom, survit même si le compte est supprimé plus tard
            $table->string('actor_role')->nullable(); // Snapshot du rôle au moment de l'action

            $table->string('action', 60); // ex: student.created, payment.collected, enrollment.cancelled
            $table->text('description');
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();

            $table->timestamps();

            $table->index(['establishment_id', 'created_at']);
            $table->index(['establishment_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_activity_logs');
    }
};