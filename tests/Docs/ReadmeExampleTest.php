<?php

declare(strict_types=1);

namespace OpenapiPhpDtoGenerator\Tests\Docs;

use OpenapiPhpDtoGenerator\Command\GenerateDtoCommand;
use OpenapiPhpDtoGenerator\Service\DtoDeserializer;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * The front page promises a specific class and specific sentences. This runs them.
 *
 * `README.md` is the most-read thing in the repository, and until this test nothing checked a word of
 * it: `tests/Docs` verified that links resolve, that emitted code is spaced correctly and that no
 * docblock is stranded — never that a claim is TRUE. Two claims elsewhere in the documentation were
 * found false by accident during one session (the boolean-subschema positions in the validation guide,
 * and a comment about the date-time patterns), which is what prompted this.
 *
 * The risk is not hypothetical for these particular lines: the error messages below were one edit away
 * from changing twice in that same session — once when the root object-constraint subject was named
 * `body`, once when interpreter violations gained a property path. Neither touched these sentences, and
 * nothing would have said so if they had.
 *
 * The spec is READ OUT OF THE README rather than copied here on purpose. A fixture of its own would
 * drift from the document it illustrates and the test would keep passing; this way, editing the YAML in
 * the README without editing the class beside it is what fails.
 */
final class ReadmeExampleTest extends TestCase
{
    private string $outputDirectory;

    protected function setUp(): void
    {
        $this->outputDirectory = __DIR__ . '/output-readme';
        if (!is_dir($this->outputDirectory)) {
            mkdir($this->outputDirectory, 0o755, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->outputDirectory . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->outputDirectory);
    }

    /**
     * The class the front page shows, member by member.
     */
    public function testTheFrontPageExampleGeneratesTheClassItShows(): void
    {
        $source = $this->generateFromTheReadmeSpec();

        $this->assertStringContainsString(
            'final class UserPostRequest implements GeneratedDtoInterface, Stringable',
            $source,
        );

        // Every line the README prints inside the class body. An optional property defaults to the
        // sentinel rather than to null — that is the claim the whole page is built on.
        foreach ([
            'private readonly string $email,',
            'private readonly int|UnsetValue|null $age = UnsetValue::UNSET,',
            'private readonly string|UnsetValue|null $nickname = UnsetValue::UNSET,',
            'public function getEmail(): string',
            'public function getAge(): ?int',
            'public function getNickname(): ?string',
            'public function isNicknameInRequest(): bool',
        ] as $shown) {
            $this->assertStringContainsString($shown, $source, 'the README shows this line');
        }
    }

    /**
     * The presence table: same getter, three different answers.
     */
    public function testTheFrontPagePresenceTableHolds(): void
    {
        $this->generateFromTheReadmeSpec();

        $cases = [
            // json, getNickname(), isNicknameInRequest()
            ['{"email":"a@b.test"}', null, false],
            ['{"email":"a@b.test","nickname":null}', null, true],
            ['{"email":"a@b.test","nickname":"neo"}', 'neo', true],
        ];

        foreach ($cases as [$json, $expectedValue, $expectedPresence]) {
            $dto = $this->deserialize($json);
            $this->assertSame($expectedValue, $dto->getNickname(), $json);
            $this->assertSame($expectedPresence, $dto->isNicknameInRequest(), $json);
        }
    }

    /**
     * The error table, which the README calls "the real output, not a paraphrase" — so it is compared
     * verbatim, both sentences and the order they arrive in.
     */
    public function testTheFrontPageErrorTableHolds(): void
    {
        $this->generateFromTheReadmeSpec();

        $this->assertSame(
            'Required parameter "email" not found in request.',
            $this->refusalFor('{"age":30}'),
        );

        $this->assertSame(
            "param \"email\" must match format email.\nparam \"age\" must be greater than or equal to 18.",
            $this->refusalFor('{"email":"nope","age":12}'),
            'both problems are reported together, in the order the page prints them',
        );
    }

    /**
     * The YAML the README shows under "60 seconds", generated into a namespace of this test's own.
     */
    private function generateFromTheReadmeSpec(): string
    {
        $readme = (string)file_get_contents(__DIR__ . '/../../README.md');

        $marker = '## 60 seconds';
        $from = strpos($readme, $marker);
        $this->assertNotFalse($from, 'the README still has its "60 seconds" section');

        $opened = strpos($readme, '```yaml', $from);
        $this->assertNotFalse($opened, 'that section still opens with a YAML block');
        $bodyStart = $opened + strlen("```yaml\n");
        $closed = strpos($readme, '```', $bodyStart);
        $this->assertNotFalse($closed, 'and closes it');

        $spec = "openapi: 3.1.0\ninfo: { title: T, version: 1.0.0 }\n"
            . substr($readme, $bodyStart, $closed - $bodyStart);

        $specFile = $this->outputDirectory . '/readme-spec.yaml';
        file_put_contents($specFile, $spec);

        (new GenerateDtoCommand())->generateFromFile(
            $specFile,
            $this->outputDirectory,
            'ReadmeExample',
        );

        $class = $this->outputDirectory . '/UserPostRequest.php';
        $this->assertFileExists($class, 'the README spec still declares UserPostRequest');
        require_once $class;

        return (string)file_get_contents($class);
    }

    private function deserialize(string $json): object
    {
        return (new DtoDeserializer())->deserialize(
            Request::create(
                uri: '/',
                method: 'POST',
                server: ['CONTENT_TYPE' => 'application/json'],
                content: $json,
            ),
            'ReadmeExample\UserPostRequest',
        );
    }

    private function refusalFor(string $json): string
    {
        try {
            $this->deserialize($json);
        } catch (Throwable $thrown) {
            return $thrown->getMessage();
        }

        throw new RuntimeException('the payload was accepted, so there is no message to compare');
    }
}
