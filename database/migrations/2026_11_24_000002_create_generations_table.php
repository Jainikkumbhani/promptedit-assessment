<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // also used as Idempotency-Key + metadata.generation_id
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();

            // submitting -> queued -> processing -> succeeded | failed
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // money in nanodollars
            $table->bigInteger('estimated_cost_nanos');
            $table->bigInteger('max_cost_nanos'); // held while running
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charged_nanos')->nullable();

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->string('retry_reason')->nullable();
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            // completed_at = billing day
            $table->timestamp('provider_created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
