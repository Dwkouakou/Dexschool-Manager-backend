<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Coefficient de chaque période (trimestre / semestre) dans la moyenne annuelle. 1 par défaut. */
    public function up(): void
    {
        if (!Schema::hasColumn('periods', 'coefficient')) {
            Schema::table('periods', function (Blueprint $table) {
                $table->decimal('coefficient', 5, 2)->default(1)->after('code');
            });
        }
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $table) {
            $table->dropColumn('coefficient');
        });
    }
}; 