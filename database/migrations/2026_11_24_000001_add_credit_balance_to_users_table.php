<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Credit balance in nanodollars (1 USD = 1,000,000,000). Always derived
            // from credit_transactions; kept here so it can be locked and read cheaply.
            $table->bigInteger('balance_nanos')->default(0)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance_nanos');
        });
    }
};
