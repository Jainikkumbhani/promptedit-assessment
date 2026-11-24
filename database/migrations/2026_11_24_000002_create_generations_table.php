<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            // Our own id doubles as the Pixelforge Idempotency-Key and metadata.generation_id.
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();

            // submitting -> queued -> processing -> succeeded | failed
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // All money is stored as integer nanodollars.
            $table->bigInteger('estimated_cost_nanos');            // typical price shown up front
            $table->bigInteger('max_cost_nanos');                  // worst case; this is what we hold
            $table->bigInteger('provider_cost_nanos')->nullable(); // what Pixelforge billed us
            $table->bigInteger('charged_nanos')->nullable();       // what the user actually paid

            $table->json('rates')->nullable();
            $table->json('usage')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // Shown while we wait out a rate/concurrency limit before Pixelforge accepts it.
            $table->string('retry_reason')->nullable();
            $table->unsignedSmallInteger('submit_attempts')->default(0);

            // Pixelforge's own timestamps (UTC). completed_at decides the billing day.
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
