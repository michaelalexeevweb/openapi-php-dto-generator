<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ResponseService
{
    private OpenApiConstraintValidator $constraintValidator;

    public function __construct(?OpenApiConstraintValidator $constraintValidator = null)
    {
        $this->constraintValidator = $constraintValidator ?? new OpenApiConstraintValidator();
    }

    /**
     * Validates DTO and converts it to HTTP response.
     *
     * @param object $dto
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers Additional headers
     * @throws RuntimeException if DTO validation fails
     */
    public function createResponse(object $dto, int $status = Response::HTTP_OK, array $headers = []): Response
    {
        $errors = $this->validateDto($dto);

        if ($errors !== []) {
            throw new RuntimeException('DTO validation failed: ' . implode(', ', $errors));
        }

        $singleFile = $this->extractSingleFileFromDto($dto);
        if ($singleFile !== null) {
            return $this->createFileResponse($singleFile, $status, $headers);
        }

        return new JsonResponse($this->dtoToArray($dto), $status, $headers);
    }

    /**
     * @param object $dto
     * @return array<string>
     */
    private function validateDto(object $dto): array
    {
        $errors = [];
        $reflection = new ReflectionClass($dto);
        $constraintsByField = $this->resolveOpenApiConstraints($reflection);

        // Get all getters
        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            $methodName = $method->getName();

            $returnType = $method->getReturnType();
            if (!$returnType instanceof ReflectionNamedType && !$returnType instanceof ReflectionUnionType) {
                continue;
            }
            $allowsNull = $returnType->allowsNull();

            $typeNames = [];
            if ($returnType instanceof ReflectionNamedType) {
                $typeNames[] = $returnType->getName();
            } else {
                foreach ($returnType->getTypes() as $unionType) {
                    if (!$unionType instanceof ReflectionNamedType) {
                        continue;
                    }
                    $typeNames[] = $unionType->getName();
                }
            }

            try {
                $value = $method->invoke($dto);

                // Validate type
                $error = $this->validateValueAgainstTypes($value, $typeNames, $allowsNull, $methodName);
                if ($error !== null) {
                    $errors[] = $error;
                }

                $propertyName = lcfirst(substr($methodName, 3));
                $constraints = $constraintsByField[$propertyName] ?? null;
                if (is_array($constraints) && $constraints !== []) {
                    $errors = array_merge(
                        $errors,
                        $this->constraintValidator->validate(sprintf('field "%s"', $propertyName), $value, $constraints)
                    );
                }
            } catch (\LogicException $e) {
                // Field wasn't provided in request - skip, it's not a validation error
                if (str_contains($e->getMessage(), "wasn't provided in request")) {
                    continue;
                }
                $errors[] = "Failed to call {$methodName}(): {$e->getMessage()}";
            } catch (\Throwable $e) {
                $errors[] = "Failed to call {$methodName}(): {$e->getMessage()}";
            }
        }

        return $errors;
    }

    /**
     * Validates single value against expected type.
     *
     * @param mixed $value
     * @param string $expectedType
     * @param bool $allowsNull
     * @param string $methodName
     * @return string|null Error message or null if valid
     */
    private function validateValue(mixed $value, string $expectedType, bool $allowsNull, string $methodName): ?string
    {
        // Null check
        if ($value === null) {
            return $allowsNull ? null : "Method {$methodName}() returned null but type is non-nullable {$expectedType}.";
        }

        // Type validation
        return match ($expectedType) {
            'int' => is_int($value) ? null : "Method {$methodName}() must return int, got " . gettype($value),
            'float' => (is_float($value) || is_int($value)) ? null : "Method {$methodName}() must return float, got " . gettype($value),
            'string' => is_string($value) ? null : "Method {$methodName}() must return string, got " . gettype($value),
            'bool' => is_bool($value) ? null : "Method {$methodName}() must return bool, got " . gettype($value),
            'array' => is_array($value) ? null : "Method {$methodName}() must return array, got " . gettype($value),
            default => $this->validateObject($value, $expectedType, $methodName),
        };
    }

    /**
     * @param array<int, string> $expectedTypes
     */
    private function validateValueAgainstTypes(mixed $value, array $expectedTypes, bool $allowsNull, string $methodName): ?string
    {
        if ($value === null) {
            return $allowsNull ? null : sprintf(
                'Method %s() returned null but type is non-nullable %s.',
                $methodName,
                implode('|', array_values(array_filter($expectedTypes, static fn (string $type): bool => $type !== 'null'))),
            );
        }

        $filtered = array_values(array_filter($expectedTypes, static fn (string $type): bool => $type !== 'null'));
        if ($filtered === []) {
            return null;
        }

        $errors = [];
        foreach ($filtered as $type) {
            $error = $this->validateValue($value, $type, false, $methodName);
            if ($error === null) {
                return null;
            }

            $errors[] = $error;
        }

        return implode(' | ', $errors);
    }

    /**
     * Validates object/enum types.
     */
    private function validateObject(mixed $value, string $expectedType, string $methodName): ?string
    {
        if (enum_exists($expectedType)) {
            return $value instanceof $expectedType
                ? null
                : "Method {$methodName}() must return enum {$expectedType}, got " . get_debug_type($value);
        }

        if (class_exists($expectedType)) {
            return $value instanceof $expectedType
                ? null
                : "Method {$methodName}() must return instance of {$expectedType}, got " . get_debug_type($value);
        }

        // Unknown type - skip validation
        return null;
    }

    /**
     * Converts DTO to array recursively.
     *
     * @param object $dto
     * @return array<string, mixed>
     */
    private function dtoToArray(object $dto): array
    {
        $result = [];
        $reflection = new ReflectionClass($dto);

        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            $methodName = $method->getName();

            // Extract property name from getter (getName -> name)
            $propertyName = lcfirst(substr($methodName, 3));

            try {
                $value = $method->invoke($dto);
            } catch (\Throwable) {
                continue;
            }

            try {
                $result[$propertyName] = $this->normalizeValue($value);
            } catch (\Throwable) {
                $result[$propertyName] = $this->normalizeValueFallback($value);
            }
        }

        return $result;
    }

    private function normalizeValueFallback(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'originalName' => $value->getClientOriginalName(),
                'clientMimeType' => $value->getClientMimeType(),
            ];
        }

        if ($value instanceof File) {
            return [
                'filename' => $value->getFilename(),
            ];
        }

        return null;
    }

    /**
     * Tries to detect a DTO that contains exactly one non-null getter value and that value is a File.
     */
    private function extractSingleFileFromDto(object $dto): ?File
    {
        $reflection = new ReflectionClass($dto);
        $nonNullValues = [];

        foreach ($reflection->getMethods() as $method) {
            if (!$this->isSerializableGetter($method)) {
                continue;
            }

            $methodName = $method->getName();

            try {
                $value = $method->invoke($dto);
            } catch (\Throwable) {
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
        return $value instanceof File ? $value : null;
    }

    /**
     * Creates stream response for a file.
     *
     * @param File|string $file File instance or absolute file path
     * @param bool $asAttachment true to force download, false for inline rendering
     * @param string|null $downloadName Optional file name shown to client
     * @param int $status HTTP status code
     * @param array<string, mixed> $headers Additional headers
     */
    public function createStreamResponse(
        File|string $file,
        bool $asAttachment = false,
        ?string $downloadName = null,
        int $status = Response::HTTP_OK,
        array $headers = []
    ): BinaryFileResponse {
        $fileObject = is_string($file) ? new File($file) : $file;

        $response = new BinaryFileResponse($fileObject->getPathname(), $status, $headers);

        if ($downloadName === null || $downloadName === '') {
            $downloadName = $fileObject instanceof UploadedFile
                ? ($fileObject->getClientOriginalName() !== '' ? $fileObject->getClientOriginalName() : $fileObject->getFilename())
                : $fileObject->getFilename();
        }

        $disposition = $asAttachment ? 'attachment' : 'inline';
        $response->setContentDisposition($disposition, $downloadName);

        // Set proper charset for non-ASCII filenames
        $response->headers->set('Content-Type', $fileObject->getMimeType() ?? 'application/octet-stream');
        $response->headers->set('Content-Transfer-Encoding', 'binary');
        $response->headers->set('Accept-Ranges', 'bytes');

        // Ensure proper UTF-8 encoding for filename in Content-Disposition
        if (preg_match('/[^\x20-\x7E]/', $downloadName)) {
            $encodedName = rawurlencode($downloadName);
            $dispositionHeader = sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $disposition,
                $downloadName,
                $encodedName
            );
            $response->headers->set('Content-Disposition', $dispositionHeader);
        }

        return $response;
    }

    /**
     * Streams a file as HTTP response.
     *
     * @param array<string, mixed> $headers
     */
    private function createFileResponse(File $file, int $status, array $headers): BinaryFileResponse
    {
        return $this->createStreamResponse($file, false, null, $status, $headers);
    }

    /**
     * Normalizes value for JSON serialization.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn($item) => $this->normalizeValue($item), $value);
        }

        // Avoid deep traversal for file objects that can cause OOM.
        if ($value instanceof File) {
            return $this->normalizeFileValue($value);
        }

        if (is_object($value) && enum_exists(get_class($value))) {
            return $value->value ?? $value->name;
        }

        if ($value instanceof \DateTimeImmutable) {
            return $value->format('c');
        }

        if (is_object($value)) {
            $reflection = new ReflectionClass($value);
            $hasGetters = false;

            foreach ($reflection->getMethods() as $method) {
                if (str_starts_with($method->getName(), 'get')) {
                    $hasGetters = true;
                    break;
                }
            }

            if ($hasGetters) {
                return $this->dtoToArray($value);
            }

            if (method_exists($value, '__toString')) {
                return (string) $value;
            }

            return get_class($value);
        }

        // Scalars pass through
        return $value;
    }

    private function isSerializableGetter(ReflectionMethod $method): bool
    {
        $methodName = $method->getName();

        if (!str_starts_with($methodName, 'get') || $methodName === 'get') {
            return false;
        }

        if ($method->isStatic() || !$method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveOpenApiConstraints(ReflectionClass $reflection): array
    {
        $className = $reflection->getName();
        if (!method_exists($className, 'getOpenApiConstraints')) {
            return [];
        }

        $constraints = $className::getOpenApiConstraints();
        return is_array($constraints) ? $constraints : [];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function normalizeFileValue(File $file): array
    {
        $mimeType = null;
        $size = null;

        try {
            $mimeType = $file->getMimeType();
        } catch (\Throwable) {
            $mimeType = null;
        }

        try {
            $size = $file->getSize();
        } catch (\Throwable) {
            $size = null;
        }

        $result = [
            'filename' => $file->getFilename(),
            'mimeType' => $mimeType,
            'size' => $size,
        ];

        if ($file instanceof UploadedFile) {
            $result['originalName'] = $file->getClientOriginalName();
            $result['clientMimeType'] = $file->getClientMimeType();
        }

        return $result;
    }
}
