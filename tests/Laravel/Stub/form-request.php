<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Http;

use Illuminate\Http\Request;

/**
 * The emitted FormRequest extends `Illuminate\Foundation\Http\FormRequest`, which ships with
 * `laravel/framework` — a dependency this package deliberately does not carry (the generated code needs
 * it, the generator does not). This stub is the smallest thing that lets the emitted class be LOADED and
 * driven: a Laravel `Request` (from `illuminate/http`, which the tests do have) with the one member the
 * generated code calls beyond the request itself.
 *
 * It is required manually and only when the real class is absent, so an environment that has the
 * framework uses the real one.
 */
class FormRequest extends Request
{
    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->json()->all();

        return $data;
    }
}
