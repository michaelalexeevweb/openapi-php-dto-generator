<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use OpenapiPhpDtoGenerator\Contract\DtoNormalizerInterface;
use OpenapiPhpDtoGenerator\Contract\ResponseServiceInterface;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class ResponseService implements ResponseServiceInterface
{
    private DtoNormalizerInterface $normalizer;

    public function __construct(
        OpenApiConstraintValidator|null $constraintValidator = null,
        OpenApiFormatRegistry|null $formatRegistry = null,
        DtoNormalizerInterface|null $normalizer = null,
    ) {
        $this->normalizer = $normalizer ?? new DtoNormalizer(
            constraintValidator: $constraintValidator,
            formatRegistry: $formatRegistry,
        );
    }

    /**
     * Validates DTO and converts it to an HTTP response.
     *
     * @param object $dto
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers Additional headers
     * @return Response
     * @throws RuntimeException if DTO validation fails
     */
    public function createResponse(object $dto, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        $singleFile = $this->extractSingleFileFromDto($dto);
        if ($singleFile !== null) {
            return $this->createFileResponse($singleFile, $status, $headers);
        }

        return new JsonResponse($this->normalizer->validateAndNormalizeToArray($dto), $status, $headers);
    }

    /**
     * Streams a file as an HTTP response.
     *
     * @param File|string $file File instance or absolute file path
     * @param bool $asAttachment true to force download, false for inline rendering
     * @param string|null $downloadName Optional filename shown to client
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers
     * @return BinaryFileResponse
     */
    public function createStreamResponse(
        File|string $file,
        bool $asAttachment = false,
        string|null $downloadName = null,
        int $status = Response::HTTP_OK,
        array $headers = [],
    ): BinaryFileResponse {
        $fileObject = is_string($file) ? new File($file) : $file;

        $response = new BinaryFileResponse($fileObject->getPathname(), $status, $headers);

        if ($downloadName === null || $downloadName === '') {
            if ($fileObject instanceof UploadedFile && $fileObject->getClientOriginalName() !== '') {
                $downloadName = $fileObject->getClientOriginalName();
            } else {
                $downloadName = $fileObject->getFilename();
            }
        }

        $disposition = $asAttachment ? 'attachment' : 'inline';
        $response->setContentDisposition($disposition, $downloadName);

        $response->headers->set('Content-Type', $fileObject->getMimeType() ?? 'application/octet-stream');
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Accept-Ranges', 'bytes');

        if (preg_match('/[^\x20-\x7E]/', $downloadName)) {
            $response->headers->set(
                'Content-Disposition',
                sprintf(
                    '%s; filename="%s"; filename*=UTF-8\'\'%s',
                    $disposition,
                    $downloadName,
                    rawurlencode($downloadName),
                ),
            );
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private function createFileResponse(File $file, int $status, array $headers): BinaryFileResponse
    {
        return $this->createStreamResponse($file, false, null, $status, $headers);
    }

    private function extractSingleFileFromDto(object $dto): File|null
    {
        $reflection = new ReflectionClass($dto);
        $nonNullValues = [];

        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            try {
                $value = $method->invoke($dto);
            } catch (Throwable) {
                continue;
            }

            if ($value !== null) {
                $nonNullValues[] = $value;
            }
        }

        if (count($nonNullValues) !== 1) {
            return null;
        }

        $value = $nonNullValues[0];

        if ($value instanceof File) {
            return $value;
        }

        return null;
    }

    private function isSerializableGetter(ReflectionMethod $method): bool
    {
        $name = $method->getName();

        if (!str_starts_with($name, 'get') || $name === 'get') {
            return false;
        }

        return !$method->isStatic() && $method->isPublic() && $method->getNumberOfRequiredParameters() === 0;
    }
}
