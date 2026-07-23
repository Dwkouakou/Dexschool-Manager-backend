<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollment_financials', function (Blueprint $table) {
            $table->bigInteger('previous_balance')->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
    Schema::table('enrolment_financials', function (Blueprint $table) {
            $table->dropColumn('previous_balance');
        });
    }
};