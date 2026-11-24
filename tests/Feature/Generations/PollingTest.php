<?php

use App\Enums\GenerationStatus;
use Illuminate\Support\Facades\Http;

it('polls generations whose webhooks never arrived', function () {
    $generation = acceptedGeneration(userWithCredits('1.00'));
    fakePixelforge(["pixelforge.promptedit.dev/v1/generations/{$generation->pixelforge_id}" => Http::response(succeededGeneration($generation->pixelforge_id))]);

    $this->travel(2)->minutes();
    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charged_nanos->toBe(381_887_500);
});

it('leaves recently updated generations alone', function () {
    fakePixelforge();
    acceptedGeneration(userWithCredits());

    $this->artisan('generations:sync')->assertSuccessful();

    Http::assertNothingSent();
});

it('gives up on submissions that never got going and returns the credits', function () {
    fakePixelforge();
    $generation = acceptedGeneration(userWithCredits('1.00'), ['status' => GenerationStatus::Submitting, 'pixelforge_id' => null]);

    $this->travel(31)->minutes();
    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user->fresh()->balance_nanos)->toBe(1_000_000_000);
});

it('lets the user check a generation right away', function () {
    $user = userWithCredits('1.00');
    $generation = acceptedGeneration($user);
    fakePixelforge(["pixelforge.promptedit.dev/v1/generations/{$generation->pixelforge_id}" => Http::response(pixelforgeGeneration(['id' => $generation->pixelforge_id, 'status' => 'processing']))]);

    $this->actingAs($user)->post("/generations/{$generation->id}/sync")->assertRedirect();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('does not let users check other people\'s generations', function () {
    fakePixelforge();
    $generation = acceptedGeneration(userWithCredits());

    $this->actingAs(userWithCredits())->post("/generations/{$generation->id}/sync")->assertNotFound();
});
