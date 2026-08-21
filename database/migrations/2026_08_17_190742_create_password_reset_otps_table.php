<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_otps', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // ─── Jamais le code en clair — mêmes précautions qu'un mot de
            // passe. On compare le hash du code saisi au hash stocké.
            $table->string('otp_hash');

            $table->timestamp('expires_at');

            // Pas de colonne "used_at" : une fois vérifié, le code est
            // directement SUPPRIMÉ (voir PasswordResetOtp::verifyFor) —
            // rien ne doit s'accumuler inutilement dans cette table.
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_otps');
    }
};