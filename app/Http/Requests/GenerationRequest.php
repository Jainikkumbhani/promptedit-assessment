<?php

namespace App\Http\Requests;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors Pixelforge's own rules so a bad request is caught here, before any
 * credits are held. Sizes and qualities come from GET /v1/pricing.
 */
class GenerationRequest extends FormRequest
{
    public const MAX_PROMPT_CHARACTERS = 4000;

    public const MAX_REFERENCE_IMAGES = 4;

    public function rules(): array
    {
        $pricing = app(PixelforgeClient::class)->pricing();

        return [
            'prompt' => [$this->isEstimate() ? 'nullable' : 'required', 'string', 'max:'.self::MAX_PROMPT_CHARACTERS],
            'size' => ['required', Rule::in($pricing->sizes())],
            'quality' => ['required', Rule::in($pricing->qualities())],
            'reference_images' => ['array', 'max:'.self::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.max' => 'You can add at most :max reference images.',
            'reference_images.*.url' => 'Each reference image must be an http(s) URL.',
        ];
    }

    /** @return list<string> */
    public function referenceImages(): array
    {
        return array_values(array_filter((array) $this->input('reference_images', []), 'filled'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['reference_images' => $this->referenceImages()]);
    }

    private function isEstimate(): bool
    {
        return $this->routeIs('generations.estimate');
    }
}
