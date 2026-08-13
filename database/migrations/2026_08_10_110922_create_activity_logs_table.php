<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('super_admin_id')->nullable()->constrained('super_admins')->nullOnDelete();
            $table->string('actor_name'); // Snapshot du nom, même si le compte est supprimé plus tard
            $table->string('action', 50); // ex: establishment.created, team.deleted
            $table->text('description');
            $table->string('subject_type', 100)->nullable(); // ex: Establishment, SuperAdmin
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamps();

            $table->index(['action']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};