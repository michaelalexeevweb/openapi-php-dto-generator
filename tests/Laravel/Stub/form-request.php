<?php

declare(strict_types=1);

namespace Illuminate\Foundation\Http;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Validation\Validator;

/**
 * The emitted FormRequest extends `Illuminate\Foundation\Http\FormRequest`, which ships with
 * `laravel/framework` — a dependency this package deliberately does not carry (the generated code needs
 * it, the generator does not). This stub is the smallest thing that lets the emitted class be LOADED and
 * driven: a Laravel `Request` (from `illuminate/http`, which the tests do have) with the members the
 * generated code calls beyond the request itself.
 *
 * It is required manually and only when the real class is absent, so an environment that has the
 * framework uses the real one.
 */
class FormRequest extends Request
{
    /** What the framework sets before resolving the request; the generated code reads its bindings. */
    protected ?Container $container = null;

    /** The validator the framework ran, which `validated()` reads as the real class does. */
    protected ?Validator $validator = null;

    public function setContainer(Container $container): static
    {
        $this->container = $container;

        return $this;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * What the real class validates: the query string and the body, as `all()` merges them.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(): array
    {
        if ($this->validator !== null) {
            /** @var array<string, mixed> $validated */
            $validated = $this->validator->validated();

            return $validated;
        }

        /** @var array<string, mixed> $data */
        $data = $this->json()->all();

        return $data;
    }
}
