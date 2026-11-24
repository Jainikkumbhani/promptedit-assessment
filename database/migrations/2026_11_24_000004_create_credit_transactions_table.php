<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('generation_id')->nullable()->constrained()->nullOnDelete();

            // signup_bonus | purchase | hold | hold_release | charge
            $table->string('type');

            // signed nanodollars
            $table->bigInteger('amount_nanos');
            $table->bigInteger('balance_after_nanos');
            $table->string('description');
            $table->timestamps();

            // each step once per generation -> no double charge
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
