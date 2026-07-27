<?php

namespace Quiote\Testing\Http;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use Quiote\Exception\QuioteException;
use Quiote\Logging\Log;

/**
 * Assertable wrapper around a PSR-7 response, returned by
 * {@see \Quiote\Testing\HttpTestCase}'s get()/post()/json() etc.
 */
final class TestResponse
{
    /** @var array<string, callable> */
    private static array $extensions = [];

    private ?string $content = null;
    private ?\SimpleXMLElement $xml = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function getPsrResponse(): ResponseInterface
    {
        return $this->response;
    }

    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    public function getHeaderLine(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    public function getContent(): string
    {
        if ($this->content === null) {
            $body = $this->response->getBody();
            $body->rewind();
            $this->content = $body->getContents();
        }
        return $this->content;
    }

    /** @return array<mixed> */
    public function json(): array
    {
        try {
            $decoded = json_decode($this->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Assert::fail('Response body is not valid JSON: ' . $e->getMessage() . "\n" . $this->getContent());
        }
        if (!is_array($decoded)) {
            Assert::fail('Response body did not decode to a JSON array/object: ' . $this->getContent());
        }
        return $decoded;
    }

    public function xml(): \SimpleXMLElement
    {
        if ($this->xml === null) {
            $previous = libxml_use_internal_errors(true);
            try {
                $parsed = simplexml_load_string($this->getContent());
                if ($parsed === false) {
                    $errors = implode('; ', array_map(static fn($e) => trim($e->message), libxml_get_errors()));
                    libxml_clear_errors();
                    Assert::fail('Response body is not valid XML: ' . $errors . "\n" . $this->getContent());
                }
                $this->xml = $parsed;
            } finally {
                libxml_use_internal_errors($previous);
            }
        }
        return $this->xml;
    }

    // --- status assertions ---------------------------------------------

    public function assertStatus(int $expected): self
    {
        Assert::assertSame($expected, $this->getStatusCode(), sprintf(
            'Expected response status %d, got %d. Body: %s',
            $expected,
            $this->getStatusCode(),
            $this->getContent()
        ));
        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertRedirect(?string $uri = null): self
    {
        $status = $this->getStatusCode();
        Assert::assertTrue(
            $status >= 300 && $status < 400,
            "Expected a redirect (3xx) status, got $status."
        );
        if ($uri !== null) {
            Assert::assertSame($uri, $this->getHeaderLine('Location'));
        }
        return $this;
    }

    // --- header assertions -----------------------------------------------

    public function assertHeader(string $name, ?string $value = null): self
    {
        Assert::assertTrue($this->response->hasHeader($name), "Response is missing header \"$name\".");
        if ($value !== null) {
            Assert::assertSame($value, $this->getHeaderLine($name));
        }
        return $this;
    }

    // --- body assertions ---------------------------------------------------

    public function assertSee(string $needle): self
    {
        Assert::assertStringContainsString($needle, $this->getContent());
        return $this;
    }

    public function assertDontSee(string $needle): self
    {
        Assert::assertStringNotContainsString($needle, $this->getContent());
        return $this;
    }

    /** @param array<mixed> $expected */
    public function assertJsonEquals(array $expected): self
    {
        Assert::assertSame($expected, $this->json());
        return $this;
    }

    /** @param array<mixed> $expected */
    public function assertJson(array $expected): self
    {
        Assert::assertTrue(
            self::isSubset($expected, $this->json()),
            "Expected JSON subset not found in response body:\n" . json_encode($expected) . "\nActual:\n" . $this->getContent()
        );
        return $this;
    }

    /**
     * Looser than {@see assertJson()}: the subset must match at least one
     * element when the decoded body is a list of records.
     * @param array<mixed> $expected
     */
    public function assertJsonFragment(array $expected): self
    {
        $actual = $this->json();
        $found = self::isSubset($expected, $actual);
        if (!$found) {
            foreach ($actual as $item) {
                if (is_array($item) && self::isSubset($expected, $item)) {
                    $found = true;
                    break;
                }
            }
        }
        Assert::assertTrue(
            $found,
            "Expected JSON fragment not found anywhere in response body:\n" . json_encode($expected) . "\nActual:\n" . $this->getContent()
        );
        return $this;
    }

    public function assertXml(string $expectedXml): self
    {
        Assert::assertSame(self::canonicalizeXml($expectedXml), self::canonicalizeXml($this->getContent()));
        return $this;
    }

    public function assertHasXPath(string $expression): self
    {
        $matches = $this->xml()->xpath($expression);
        Assert::assertNotEmpty($matches, "XPath expression \"$expression\" matched nothing in response body:\n" . $this->getContent());
        return $this;
    }

    // --- extensibility -------------------------------------------------------

    public static function extend(string $name, callable $assertion): void
    {
        if (isset(self::$extensions[$name])) {
            Log::for(self::class)->warning("[TestResponse] extension \"$name\" is being overwritten.");
        }
        self::$extensions[$name] = $assertion;
    }

    public static function hasExtension(string $name): bool
    {
        return isset(self::$extensions[$name]);
    }

    public static function clearExtensions(): void
    {
        self::$extensions = [];
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        $extension = self::$extensions[$name] ?? null;
        if ($extension === null) {
            $suggestion = self::closestExtensionName($name);
            $hint = $suggestion !== null ? " Did you mean \"$suggestion\"?" : '';
            throw new QuioteException("TestResponse has no method or registered extension \"$name\"." . $hint);
        }
        return \Closure::fromCallable($extension)->call($this, ...$arguments);
    }

    private static function closestExtensionName(string $name): ?string
    {
        $best = null;
        $bestDistance = null;
        foreach (array_keys(self::$extensions) as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /**
     * @param array<mixed> $subset
     * @param array<mixed> $superset
     */
    private static function isSubset(array $subset, array $superset): bool
    {
        foreach ($subset as $key => $value) {
            if (!array_key_exists($key, $superset)) {
                return false;
            }
            if (is_array($value)) {
                if (!is_array($superset[$key]) || !self::isSubset($value, $superset[$key])) {
                    return false;
                }
            } elseif ($superset[$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    private static function canonicalizeXml(string $xml): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($xml)) {
                $errors = implode('; ', array_map(static fn($e) => trim($e->message), libxml_get_errors()));
                libxml_clear_errors();
                Assert::fail('Not valid XML: ' . $errors . "\n" . $xml);
            }
            $canonical = $document->C14N();
            if ($canonical === false) {
                Assert::fail('Failed to canonicalize XML for comparison.');
            }
            return $canonical;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
