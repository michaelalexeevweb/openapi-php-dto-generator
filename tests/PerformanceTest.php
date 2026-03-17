<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests;

use DateTimeImmutable;
use OpenapiPhpDtoGenerator\Service\DtoNormalizer;
use OpenapiPhpDtoGenerator\Service\RequestDeserializerService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

function writePerfLine(string $message): void
{
    $enabled = getenv('OPENAPI_DTO_PERF_OUTPUT');

    if ($enabled === false || $enabled === '' || $enabled === '0') {
        return;
    }

    fwrite(STDOUT, $message);
}

/**
 * Performance benchmarks for deserialization and normalization.
 *
 * Run with:
 *   vendor/bin/phpunit --filter PerformanceTest --no-coverage
 *
 * The tests measure wall-clock time for N iterations over a realistic DTO
 * and assert a loose upper bound that should be met even on slow CI runners.
 */
final class PerformanceTest extends TestCase
{
    private const ITERATIONS = 500;
    private const LARGE_ITERATIONS = 200;
    private const LARGE_TAG_COUNT = 120;

    private RequestDeserializerService $deserializer;
    private DtoNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->deserializer = new RequestDeserializerService();
        $this->normalizer   = new DtoNormalizer();
    }

    // -----------------------------------------------------------------------
    // Deserialization
    // -----------------------------------------------------------------------

    public function testDeserializationPerformance(): void
    {
        $json = json_encode($this->buildPerfPayload(), JSON_THROW_ON_ERROR);

        // warm-up (reflection caches populate on 1st call)
        $warm = new Request([], [], [], [], [], [], $json);
        $warm->headers->set('Content-Type', 'application/json');
        $this->deserializer->deserialize($warm, PerfTestRequestDto::class);

        $start = hrtime(true);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $request = new Request([], [], [], [], [], [], $json);
            $request->headers->set('Content-Type', 'application/json');
            $this->deserializer->deserialize($request, PerfTestRequestDto::class);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / self::ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] deserialize: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        // Should handle at least 500 deserializations in under 3 seconds
        $this->assertLessThan(
            3_000,
            $elapsedMs,
            sprintf('Deserialization too slow: %.1f ms for %d iterations (%.3f ms/op)', $elapsedMs, self::ITERATIONS, $perOpMs),
        );
    }

    public function testDeserializationPerformanceRepeatedSameRequest(): void
    {
        // Simulates the common case where the same DTO class is deserialized
        // many times across many HTTP requests (all caches populated after 1st call).
        $json = json_encode($this->buildPerfPayload(), JSON_THROW_ON_ERROR);
        $request = new Request([], [], [], [], [], [], $json);
        $request->headers->set('Content-Type', 'application/json');

        // warm-up
        $this->deserializer->deserialize($request, PerfTestRequestDto::class);

        $start = hrtime(true);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $req = new Request([], [], [], [], [], [], $json);
            $req->headers->set('Content-Type', 'application/json');
            $this->deserializer->deserialize($req, PerfTestRequestDto::class);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / self::ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] deserialize (cached): %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        $this->assertLessThan(2_000, $elapsedMs);
    }

    // -----------------------------------------------------------------------
    // Normalization
    // -----------------------------------------------------------------------

    public function testNormalizationPerformance(): void
    {
        $dto = $this->buildPerfDto();

        // warm-up
        $this->normalizer->toArray($dto);

        $start = hrtime(true);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->normalizer->toArray($dto);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / self::ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] normalize: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        $this->assertLessThan(2_000, $elapsedMs);
    }

    public function testValidateAndNormalizePerformance(): void
    {
        $dto = $this->buildPerfDto();

        // warm-up
        $this->normalizer->validateAndNormalizeToArray($dto);

        $start = hrtime(true);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $this->normalizer->validateAndNormalizeToArray($dto);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / self::ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] validate+normalize: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        $this->assertLessThan(3_000, $elapsedMs);
    }

    // -----------------------------------------------------------------------
    // Round-trip: deserialize → normalize
    // -----------------------------------------------------------------------

    public function testRoundTripPerformance(): void
    {
        $json = json_encode($this->buildPerfPayload(), JSON_THROW_ON_ERROR);

        // warm-up
        $warm = new Request([], [], [], [], [], [], $json);
        $warm->headers->set('Content-Type', 'application/json');
        $dto = $this->deserializer->deserialize($warm, PerfTestRequestDto::class);
        $this->normalizer->toArray($dto);

        $start = hrtime(true);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $req = new Request([], [], [], [], [], [], $json);
            $req->headers->set('Content-Type', 'application/json');
            $dto = $this->deserializer->deserialize($req, PerfTestRequestDto::class);
            $this->normalizer->toArray($dto);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / self::ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] round-trip: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        $this->assertLessThan(4_000, $elapsedMs);
    }

    // -----------------------------------------------------------------------
    // Generated-style DTO (has isXxxRequired / file-reading hotspot)
    // -----------------------------------------------------------------------

    public function testGeneratedStyleDtoDeserializationPerformance(): void
    {
        $json = json_encode([
            'user-id'   => 7,
            'user-name' => 'alice',
            'email'     => 'alice@example.com',
            'score'     => 75.5,
            'active'    => true,
            'role'      => 'admin',
        ], JSON_THROW_ON_ERROR);

        // warm-up
        $w = new Request([], [], [], [], [], [], $json);
        $w->headers->set('Content-Type', 'application/json');
        $this->deserializer->deserialize($w, PerfGeneratedStyleDto::class);

        $start = hrtime(true);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $req = new Request([], [], [], [], [], [], $json);
            $req->headers->set('Content-Type', 'application/json');
            $this->deserializer->deserialize($req, PerfGeneratedStyleDto::class);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / self::ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] generated-style DTO deserialize: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        // isXxxRequired() file-reading path must be cached after 1st call → stays fast
        $this->assertLessThan(2_000, $elapsedMs);
    }

    public function testLargePayloadDeserializationPerformance(): void
    {
        $json = json_encode($this->buildLargePerfPayload(150), JSON_THROW_ON_ERROR);

        $warm = new Request([], [], [], [], [], [], $json);
        $warm->headers->set('Content-Type', 'application/json');
        $this->deserializer->deserialize($warm, PerfTestRequestDto::class);

        $start = hrtime(true);

        for ($i = 0; $i < self::LARGE_ITERATIONS; $i++) {
            $req = new Request([], [], [], [], [], [], $json);
            $req->headers->set('Content-Type', 'application/json');
            $this->deserializer->deserialize($req, PerfTestRequestDto::class);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs = $elapsedMs / self::LARGE_ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] large deserialize: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::LARGE_ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        $this->assertLessThan(2_500, $elapsedMs);
    }

    public function testLargePayloadRoundTripPerformance(): void
    {
        $tagCount = self::LARGE_TAG_COUNT;
        $json = json_encode($this->buildLargePerfPayload($tagCount), JSON_THROW_ON_ERROR);

        $warm = new Request([], [], [], [], [], [], $json);
        $warm->headers->set('Content-Type', 'application/json');
        $dto = $this->deserializer->deserialize($warm, PerfTestRequestDto::class);
        $this->normalizer->toArray($dto);

        $start = hrtime(true);

        for ($i = 0; $i < self::LARGE_ITERATIONS; $i++) {
            $req = new Request([], [], [], [], [], [], $json);
            $req->headers->set('Content-Type', 'application/json');
            $dto = $this->deserializer->deserialize($req, PerfTestRequestDto::class);
            $this->normalizer->toArray($dto);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs = $elapsedMs / self::LARGE_ITERATIONS;

        writePerfLine(sprintf(
            "\n  [Perf] large round-trip: %d iterations → %.1f ms total (%.3f ms/op)\n",
            self::LARGE_ITERATIONS,
            $elapsedMs,
            $perOpMs,
        ));

        $this->assertLessThan(3_000, $elapsedMs);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildPerfPayload(): array
    {
        return [
            'userId'      => 42,
            'username'    => 'john_doe',
            'email'       => 'john@example.com',
            'score'       => 98.6,
            'active'      => true,
            'role'        => 'editor',
            'createdAt'   => '2024-06-01T12:00:00+00:00',
            'description' => 'A test user description',
            'address'     => [
                'street'  => '123 Main St',
                'city'    => 'Springfield',
                'country' => 'US',
            ],
            'tags'        => [
                ['id' => 1, 'label' => 'php'],
                ['id' => 2, 'label' => 'openapi'],
                ['id' => 3, 'label' => 'performance'],
            ],
        ];
    }

    private function buildPerfDto(): PerfTestRequestDto
    {
        return new PerfTestRequestDto(
            userId: 42,
            username: 'john_doe',
            email: 'john@example.com',
            score: 98.6,
            active: true,
            role: PerfRoleEnum::EDITOR,
            createdAt: new DateTimeImmutable('2024-06-01T12:00:00+00:00'),
            description: 'A test user description',
            address: new PerfAddressDto('123 Main St', 'Springfield', 'US'),
            tags: [
                new PerfTagDto(1, 'php'),
                new PerfTagDto(2, 'openapi'),
                new PerfTagDto(3, 'performance'),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLargePerfPayload(int $tagCount): array
    {
        $payload = $this->buildPerfPayload();
        $tags = [];

        for ($i = 1; $i <= $tagCount; $i++) {
            $tags[] = ['id' => $i, 'label' => 'tag-' . $i];
        }

        $payload['tags'] = $tags;
        return $payload;
    }
}

// ============================================================================
// DTO definitions used only by PerformanceTest
// ============================================================================

/**
 * Mirrors the pattern used by auto-generated DTOs where every field has
 * isXxxRequired() / isXxxInPath() / isXxxInQuery() methods.
 * This stresses the resolveSchemaAllowsNull() path that reads PHP source files.
 */
final class PerfGeneratedStyleDto
{
    private bool $userIdInRequest   = false;
    private bool $usernameInRequest = false;
    private bool $emailInRequest    = false;
    private bool $scoreInRequest    = false;
    private bool $activeInRequest   = false;
    private bool $roleInRequest     = false;

    public function __construct(
        private int $userId,
        private string $username,
        private string $email,
        private float $score,
        private bool $active,
        private ?string $role,
    ) {
    }

    public function getUserId(): int { return $this->userId; }
    public function isUserIdRequired(): bool { return true; }
    public function isUserIdInPath(): bool { return false; }
    public function isUserIdInQuery(): bool { return false; }
    public function isUserIdInRequest(): bool { return $this->userIdInRequest; }

    public function getUsername(): string { return $this->username; }
    public function isUsernameRequired(): bool { return true; }
    public function isUsernameInPath(): bool { return false; }
    public function isUsernameInQuery(): bool { return false; }
    public function isUsernameInRequest(): bool { return $this->usernameInRequest; }

    public function getEmail(): string { return $this->email; }
    public function isEmailRequired(): bool { return true; }
    public function isEmailInPath(): bool { return false; }
    public function isEmailInQuery(): bool { return false; }
    public function isEmailInRequest(): bool { return $this->emailInRequest; }

    public function getScore(): float { return $this->score; }
    public function isScoreRequired(): bool { return true; }
    public function isScoreInPath(): bool { return false; }
    public function isScoreInQuery(): bool { return false; }
    public function isScoreInRequest(): bool { return $this->scoreInRequest; }

    public function isActive(): bool { return $this->active; }
    public function isActiveRequired(): bool { return true; }
    public function isActiveInPath(): bool { return false; }
    public function isActiveInQuery(): bool { return false; }
    public function isActiveInRequest(): bool { return $this->activeInRequest; }

    public function getRole(): ?string { return $this->role; }
    /** nullable + required → schema has nullable:true */
    public function isRoleRequired(): bool { return true; }
    public function isRoleInPath(): bool { return false; }
    public function isRoleInQuery(): bool { return false; }
    public function isRoleInRequest(): bool { return $this->roleInRequest; }

    /** @return array<string, array<string, mixed>> */
    public static function getConstraints(): array
    {
        return [
            'email' => ['format' => 'email'],
            'score' => ['minimum' => 0, 'maximum' => 100],
        ];
    }

    /** @return array<string, string> */
    public static function getAliases(): array
    {
        return ['userId' => 'user-id', 'username' => 'user-name'];
    }
}

final class PerformanceTest_GeneratedStyleTest extends TestCase
{
    public function testGeneratedStyleDtoDeserializationPerformance(): void
    {
        $deserializer = new RequestDeserializerService();
        $json = json_encode([
            'user-id'   => 7,
            'user-name' => 'alice',
            'email'     => 'alice@example.com',
            'score'     => 75.5,
            'active'    => true,
            'role'      => 'admin',
        ], JSON_THROW_ON_ERROR);

        // warm-up
        $w = new Request([], [], [], [], [], [], $json);
        $w->headers->set('Content-Type', 'application/json');
        $deserializer->deserialize($w, PerfGeneratedStyleDto::class);

        $iterations = 500;
        $start = hrtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $req = new Request([], [], [], [], [], [], $json);
            $req->headers->set('Content-Type', 'application/json');
            $deserializer->deserialize($req, PerfGeneratedStyleDto::class);
        }

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $perOpMs   = $elapsedMs / $iterations;

        writePerfLine(sprintf(
            "\n  [Perf] generated-style DTO deserialize: %d iterations → %.1f ms total (%.3f ms/op)\n",
            $iterations,
            $elapsedMs,
            $perOpMs,
        ));

        // isXxxRequired() path (file reading) should be cached → must stay fast
        $this->assertLessThan(2_000, $elapsedMs);
    }
}

enum PerfRoleEnum: string
{
    case ADMIN  = 'admin';
    case EDITOR = 'editor';
    case VIEWER = 'viewer';
}

final class PerfTagDto
{
    public function __construct(
        private readonly int $id,
        private readonly string $label,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }
}

final class PerfAddressDto
{
    public function __construct(
        private readonly string $street,
        private readonly string $city,
        private readonly string $country,
    ) {
    }

    public function getStreet(): string
    {
        return $this->street;
    }

    public function getCity(): string
    {
        return $this->city;
    }

    public function getCountry(): string
    {
        return $this->country;
    }
}

final class PerfTestRequestDto
{
    /** @var array<PerfTagDto> */
    private array $tags;

    /**
     * @param array<PerfTagDto> $tags
     */
    public function __construct(
        private readonly int $userId,
        private readonly string $username,
        private readonly string $email,
        private readonly float $score,
        private readonly bool $active,
        private readonly PerfRoleEnum $role,
        private readonly DateTimeImmutable $createdAt,
        private readonly ?string $description,
        private readonly PerfAddressDto $address,
        array $tags,
    ) {
        $this->tags = $tags;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function getRole(): PerfRoleEnum
    {
        return $this->role;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getAddress(): PerfAddressDto
    {
        return $this->address;
    }

    /** @return array<PerfTagDto> */
    public function getTags(): array
    {
        return $this->tags;
    }
}
