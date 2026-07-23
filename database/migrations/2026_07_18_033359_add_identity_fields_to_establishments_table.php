<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->string('sigle')->nullable()->after('code');
            $table->string('phone')->nullable()->after('sigle');
            $table->string('email')->nullable()->after('phone');
            $table->string('website')->nullable()->after('email');
            $table->string('address')->nullable()->after('website');
            $table->string('director_name')->nullable()->after('address');
            $table->date('founded_date')->nullable()->after('director_name');
            $table->string('motto')->nullable()->after('founded_date');
            $table->string('logo')->nullable()->after('motto');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn(['sigle', 'phone', 'email', 'website', 'address', 'director_name', 'founded_date', 'motto', 'logo']);
        });
    }
};