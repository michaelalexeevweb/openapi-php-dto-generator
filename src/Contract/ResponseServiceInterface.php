<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Contract;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

interface ResponseServiceInterface
{
    /**
     * Validates DTO and converts it to an HTTP response.
     *
     * @param array<string, mixed> $headers
     * @throws RuntimeException if DTO validation fails
     */
    public function createResponse(object $dto, int $status = Response::HTTP_OK, array $headers = []): Response;

    /**
     * Streams a file as an HTTP response.
     *
     * @param File|string $file File instance or absolute file path
     * @param bool $asAttachment true to force download, false for inline rendering
     * @param string|null $downloadName Optional filename shown to client
     * @param array<string, mixed> $headers
     */
    public function createStreamResponse(
        File|string $file,
        bool $asAttachment = false,
        string|null $downloadName = null,
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): BinaryFileResponse;
}

