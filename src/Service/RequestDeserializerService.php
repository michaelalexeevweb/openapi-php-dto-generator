<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Service;

use DateTimeImmutable;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class RequestDeserializerService
{
    /**
     * @template T
     * @param class-string<T> $dtoClass
     * @return T
     */
    public function deserialize(Request $request, string $dtoClass): object
    {
        $reflection = new ReflectionClass($dtoClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            throw new RuntimeException(sprintf('DTO %s has no constructor.', $dtoClass));
        }

        $params = $constructor->getParameters();
        $args = [];
        $providedParams = [];

        foreach ($params as $param) {
            $paramName = $param->getName();
            $paramType = $param->getType();

            if (!$paramType instanceof ReflectionNamedType) {
                throw new RuntimeException(sprintf('Parameter $%s in %s has unsupported type.', $paramName, $dtoClass));
            }

            $typeName = $paramType->getName();
            $allowsNull = $paramType->allowsNull();

            // Try to get value from request (body, query, path, files)
            $wasProvided = false;
            $value = $this->extractValueFromRequest($request, $paramName, $typeName, $allowsNull, $wasProvided);

            $args[] = $value;
            if ($wasProvided) {
                $providedParams[] = $paramName;
            }
        }

        $dto = $reflection->newInstanceArgs($args);

        // Mark fields as provided in request
        foreach ($providedParams as $paramName) {
            $markMethodName = 'markAs' . ucfirst($paramName) . 'ProvidedInRequest';
            if ($reflection->hasMethod($markMethodName)) {
                $markMethod = $reflection->getMethod($markMethodName);
                $markMethod->invoke($dto);
            }
        }

        return $dto;
    }

    private function extractValueFromRequest(Request $request, string $paramName, string $typeName, bool $allowsNull, bool &$wasProvided): mixed
    {
        // Check in request body (JSON)
        $bodyData = $this->getBodyData($request);
        if (array_key_exists($paramName, $bodyData)) {
            $wasProvided = true;
            return $this->castValue($bodyData[$paramName], $typeName, $allowsNull, $request);
        }

        // Check in query parameters
        if ($request->query->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->query->get($paramName), $typeName, $allowsNull, $request);
        }

        // Check in route parameters (path)
        if ($request->attributes->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->attributes->get($paramName), $typeName, $allowsNull, $request);
        }

        // Check in uploaded files
        if ($typeName === UploadedFile::class && $request->files->has($paramName)) {
            $wasProvided = true;
            return $request->files->get($paramName);
        }

        // Check in multipart form data
        if ($request->request->has($paramName)) {
            $wasProvided = true;
            return $this->castValue($request->request->get($paramName), $typeName, $allowsNull, $request);
        }

        // If nullable and not found, return null
        if ($allowsNull) {
            $wasProvided = false;
            return null;
        }

        throw new RuntimeException(sprintf('Required parameter "%s" not found in request.', $paramName));
    }

    /**
     * @return array<string, mixed>
     */
    private function getBodyData(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '' || $content === false) {
            return [];
        }

        $contentType = $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($content, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function castValue(mixed $value, string $typeName, bool $allowsNull, Request $request): mixed
    {
        if ($value === null) {
            if ($allowsNull) {
                return null;
            }
            throw new RuntimeException(sprintf('Cannot cast null to non-nullable type %s.', $typeName));
        }

        // Handle scalar types
        if ($typeName === 'int') {
            return (int) $value;
        }

        if ($typeName === 'float') {
            return (float) $value;
        }

        if ($typeName === 'string') {
            return (string) $value;
        }

        if ($typeName === 'bool') {
            if (is_bool($value)) {
                return $value;
            }
            if (is_string($value)) {
                return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
            }
            return (bool) $value;
        }

        if ($typeName === 'array') {
            return is_array($value) ? $value : [$value];
        }

        // Handle DateTimeImmutable
        if ($typeName === DateTimeImmutable::class) {
            if ($value instanceof DateTimeImmutable) {
                return $value;
            }
            if (is_string($value)) {
                try {
                    return new DateTimeImmutable($value);
                } catch (\Exception $e) {
                    throw new RuntimeException(sprintf('Cannot parse date/time from "%s": %s', $value, $e->getMessage()));
                }
            }
            throw new RuntimeException(sprintf('Cannot convert value to DateTimeImmutable.'));
        }

        // Handle UploadedFile
        if ($typeName === UploadedFile::class) {
            if ($value instanceof UploadedFile) {
                return $value;
            }
            throw new RuntimeException('Expected UploadedFile but got something else.');
        }

        // Handle nested DTOs
        if (class_exists($typeName)) {
            if (is_array($value)) {
                // Recursively deserialize nested DTO
                $nestedRequest = $this->createRequestFromArray($value);
                return $this->deserialize($nestedRequest, $typeName);
            }
            throw new RuntimeException(sprintf('Cannot deserialize nested DTO %s from non-array value.', $typeName));
        }

        // Handle enums (PHP 8.1+)
        if (enum_exists($typeName)) {
            return $this->castToEnum($value, $typeName);
        }

        throw new RuntimeException(sprintf('Unsupported type: %s', $typeName));
    }

    private function castToEnum(mixed $value, string $enumClass): object
    {
        $reflection = new ReflectionClass($enumClass);
        $cases = $reflection->getMethod('cases')->invoke(null);

        foreach ($cases as $case) {
            if (property_exists($case, 'value') && $case->value === $value) {
                return $case;
            }
            if ($case->name === $value) {
                return $case;
            }
        }

        throw new RuntimeException(sprintf('Invalid enum value "%s" for %s.', (string) $value, $enumClass));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createRequestFromArray(array $data): Request
    {
        // Create a minimal request from array data
        $request = new Request();
        $request->initialize([], [], [], [], [], [], json_encode($data));
        $request->headers->set('Content-Type', 'application/json');
        return $request;
    }
}

