<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Http\ProblemDetails;

final class ProblemDetailsTest extends TestCase
{
    public function testAboutBlankUsesStatusPhraseAsTitle(): void
    {
        $p = ProblemDetails::create(status: 400);
        $arr = $p->toArray();
        $this->assertSame('about:blank', $arr['type']);
        $this->assertSame('Bad Request', $arr['title']);
        $this->assertSame(400, $arr['status']);
        // No optional members when not provided.
        $this->assertArrayNotHasKey('detail', $arr);
        $this->assertArrayNotHasKey('instance', $arr);
        $this->assertArrayNotHasKey('errors', $arr);
    }

    public function testCustomTypeKeepsGivenTitleAndOptionalMembers(): void
    {
        $p = ProblemDetails::create(
            status: 422,
            title: 'Validation Failed',
            type: 'https://example.com/probs/validation',
            detail: 'The order could not be created.',
            instance: '/orders/offers/new',
            errors: ['quantity' => ['must be positive']],
            extensions: ['traceId' => 'abc123'],
        );
        $arr = $p->toArray();
        $this->assertSame('https://example.com/probs/validation', $arr['type']);
        $this->assertSame('Validation Failed', $arr['title']);
        $this->assertSame(422, $arr['status']);
        $this->assertSame('The order could not be created.', $arr['detail']);
        $this->assertSame('/orders/offers/new', $arr['instance']);
        $this->assertSame(['quantity' => ['must be positive']], $arr['errors']);
        $this->assertSame('abc123', $arr['traceId']);
    }

    public function testExtensionsCannotOverrideCoreMembers(): void
    {
        $p = ProblemDetails::create(status: 400, extensions: ['status' => 999, 'foo' => 'bar']);
        $arr = $p->toArray();
        $this->assertSame(400, $arr['status'], 'core status must not be overridden by an extension');
        $this->assertSame('bar', $arr['foo']);
    }

    public function testMediaTypeAndValidJson(): void
    {
        $this->assertSame('application/problem+json', ProblemDetails::MEDIA_TYPE);
        $json = ProblemDetails::create(status: 400, errors: ['x' => ['bad']])->toJson();
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['x' => ['bad']], $decoded['errors']);
    }

    public function testExtractErrorsFromValidationManager(): void
    {
        $vm = $this->fakeValidationManager([
            ['fields' => ['quantity'], 'message' => 'must be positive'],
            ['fields' => ['quantity'], 'message' => 'must be positive'], // duplicate -> deduped
            ['fields' => ['price', 'currency'], 'message' => 'is required'],
            ['fields' => [], 'message' => 'order is invalid'], // non-field -> ""
        ]);

        $errors = ProblemDetails::extractErrors($vm);

        $this->assertSame(['must be positive'], $errors['quantity']);
        $this->assertSame(['is required'], $errors['price']);
        $this->assertSame(['is required'], $errors['currency']);
        $this->assertSame(['order is invalid'], $errors['']);
    }

    public function testFromValidationManagerBuildsProblem(): void
    {
        $vm = $this->fakeValidationManager([
            ['fields' => ['email'], 'message' => 'invalid'],
        ]);
        $arr = ProblemDetails::fromValidationManager($vm, status: 400, instance: '/x')->toArray();
        $this->assertSame(400, $arr['status']);
        $this->assertSame('/x', $arr['instance']);
        $this->assertSame(['email' => ['invalid']], $arr['errors']);
    }

    public function testExtractErrorsToleratesNullManager(): void
    {
        $this->assertSame([], ProblemDetails::extractErrors(null));
    }

    /**
     * Build a minimal validation-manager-shaped object whose report yields the
     * given incidents (each: fields[], message).
     * @param list<array{fields: string[], message: string}> $incidentsSpec
     */
    private function fakeValidationManager(array $incidentsSpec): object
    {
        $incidents = [];
        foreach ($incidentsSpec as $spec) {
            $args = [];
            foreach ($spec['fields'] as $f) {
                $args[] = new readonly class($f) {
                    public function __construct(private string $n) {}
                    public function getName(): string { return $this->n; }
                };
            }
            $err = new readonly class($spec['message']) {
                public function __construct(private string $m) {}
                public function getMessage(): string { return $this->m; }
            };
            $incidents[] = new readonly class($args, $err) {
                public function __construct(private array $args, private object $err) {}
                public function getArguments(): array { return $this->args; }
                public function getErrors(): array { return [$this->err]; }
            };
        }
        $report = new readonly class($incidents) {
            public function __construct(private array $incidents) {}
            public function getIncidents(): array { return $this->incidents; }
        };
        return new readonly class($report) {
            public function __construct(private object $report) {}
            public function getReport(): object { return $this->report; }
        };
    }
}
