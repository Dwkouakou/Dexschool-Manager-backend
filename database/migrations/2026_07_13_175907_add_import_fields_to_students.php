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
        Schema::table('students', function (Blueprint $table) {
            $table->string('nationality', 100)->nullable()->after('address');
            $table->string('national_matricule', 50)->nullable()->after('matricule');
            $table->string('provisional_matricule', 50)->nullable()->after('national_matricule');
            $table->string('origin_school', 200)->nullable()->after('provisional_matricule');
            $table->boolean('is_transferred')->default(false)->after('is_active');
            $table->boolean('is_enrolled')->default(false)->after('is_transferred');
            $table->enum('assignment_status', ['affecte', 'non_affecte'])->default('non_affecte')->after('is_enrolled');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['nationality','national_matricule','provisional_matricule','origin_school','is_transferred','is_enrolled','assignment_status']);
        });
    }
};
