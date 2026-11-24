<?php

use App\Services\Pixelforge\WebhookSignature;

beforeEach(function () {
    $this->signature = new WebhookSignature('whsec_test', 300);
    $this->body = '{"id":"evt_1","type":"generation.succeeded"}';
});

it('accepts a correctly signed request', function () {
    $header = $this->signature->sign($this->body, time());

    expect($this->signature->isValid($this->body, $header))->toBeTrue();
});

it('rejects a tampered body', function () {
    $header = $this->signature->sign($this->body, time());

    expect($this->signature->isValid($this->body.' ', $header))->toBeFalse();
});

it('rejects a signature made with another secret', function () {
    $header = (new WebhookSignature('whsec_other'))->sign($this->body, time());

    expect($this->signature->isValid($this->body, $header))->toBeFalse();
});

it('rejects old timestamps to stop replays', function () {
    $header = $this->signature->sign($this->body, time() - 301);

    expect($this->signature->isValid($this->body, $header))->toBeFalse();
});

it('rejects missing or malformed headers', function (?string $header) {
    expect($this->signature->isValid($this->body, $header))->toBeFalse();
})->with([null, '', 'garbage', 't=abc,v1=00', 't=123']);

it('rejects everything when no secret is configured', function () {
    $unconfigured = new WebhookSignature(null);

    expect($unconfigured->isValid($this->body, $this->signature->sign($this->body, time())))->toBeFalse();
});
