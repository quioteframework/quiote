<?php

use PHPUnit\Framework\TestCase;
use Nyholm\Psr7\Response;
use Quiote\Exception\QuioteException;
use Quiote\Testing\Http\TestResponse;

/**
 * Exercises TestResponse's assertions directly against literal PSR-7
 * responses, without going through Context/MiddlewarePipeline -- the HTTP
 * dispatch mechanics are covered separately by HttpTestCaseTest.
 */
class TestResponseTest extends TestCase
{
    #[\Override]
    public function tearDown(): void
    {
        TestResponse::clearExtensions();
        parent::tearDown();
    }

    /** @param array<string, string> $headers */
    private function make(int $status = 200, array $headers = [], string $body = ''): TestResponse
    {
        return new TestResponse(new Response($status, $headers, $body));
    }

    /** @param array<mixed> $data */
    private function encode(array $data): string
    {
        $json = json_encode($data);
        self::assertIsString($json);
        return $json;
    }

    /**
     * Widens a literal method name to a plain (non-constant) string so
     * dynamic calls below are analysed as genuinely unknown method names,
     * matching the runtime behaviour under test: __call() dispatch to
     * assertion extensions that may or may not be registered.
     */
    private function extensionMethodName(string $name): string
    {
        return $name;
    }

    public function testAssertOkPassesOn200(): void
    {
        $this->make(200)->assertOk();
    }

    public function testAssertStatusFailsOnMismatch(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(404)->assertOk();
    }

    public function testAssertNotFoundPassesOn404(): void
    {
        $this->make(404)->assertNotFound();
    }

    public function testAssertRedirectChecksLocationHeader(): void
    {
        $this->make(302, ['Location' => '/next'])->assertRedirect('/next');
    }

    public function testAssertRedirectFailsOnNon3xx(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200)->assertRedirect();
    }

    public function testAssertHeaderChecksNameAndValue(): void
    {
        $this->make(200, ['X-Foo' => 'bar'])->assertHeader('X-Foo', 'bar');
    }

    public function testAssertHeaderFailsWhenMissing(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200)->assertHeader('X-Missing');
    }

    public function testAssertSeeAndDontSee(): void
    {
        $response = $this->make(200, [], 'hello world');
        $response->assertSee('hello');
        $response->assertDontSee('goodbye');
    }

    public function testJsonDecodesBody(): void
    {
        $response = $this->make(200, [], $this->encode(['a' => 1]));
        $this->assertSame(['a' => 1], $response->json());
    }

    public function testJsonFailsOnInvalidBody(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200, [], 'not json')->json();
    }

    public function testAssertJsonMatchesSubset(): void
    {
        $response = $this->make(200, [], $this->encode(['a' => 1, 'b' => ['c' => 2]]));
        $response->assertJson(['a' => 1]);
        $response->assertJson(['b' => ['c' => 2]]);
    }

    public function testAssertJsonFailsWhenKeyMissing(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200, [], $this->encode(['a' => 1]))->assertJson(['b' => 2]);
    }

    public function testAssertJsonEqualsRequiresExactMatch(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200, [], $this->encode(['a' => 1, 'b' => 2]))->assertJsonEquals(['a' => 1]);
    }

    public function testAssertJsonFragmentMatchesAnyListItem(): void
    {
        $response = $this->make(200, [], $this->encode([['id' => 1], ['id' => 2, 'name' => 'x']]));
        $response->assertJsonFragment(['name' => 'x']);
    }

    public function testAssertJsonFragmentFailsWhenNotPresent(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $response = $this->make(200, [], $this->encode([['id' => 1], ['id' => 2]]));
        $response->assertJsonFragment(['name' => 'nope']);
    }

    public function testXmlParsesWellFormedBody(): void
    {
        $response = $this->make(200, [], '<root><child>value</child></root>');
        $this->assertSame('value', (string)$response->xml()->child);
    }

    public function testXmlFailsOnMalformedBody(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200, [], '<root><unclosed></root>')->xml();
    }

    public function testAssertXmlComparesCanonicalizedDocuments(): void
    {
        // Attribute order and whitespace differ but the documents are equivalent.
        $response = $this->make(200, [], '<root b="2" a="1">text</root>');
        $response->assertXml('<root a="1" b="2">text</root>');
    }

    public function testAssertXmlFailsOnRealDifference(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200, [], '<root>one</root>')->assertXml('<root>two</root>');
    }

    public function testAssertHasXPathFindsMatch(): void
    {
        $this->make(200, [], '<root><item id="1"/><item id="2"/></root>')
            ->assertHasXPath('//item[@id="2"]');
    }

    public function testAssertHasXPathFailsWhenNoMatch(): void
    {
        $this->expectException(\PHPUnit\Framework\AssertionFailedError::class);
        $this->make(200, [], '<root><item id="1"/></root>')->assertHasXPath('//item[@id="9"]');
    }

    public function testExtendRegistersCustomAssertion(): void
    {
        TestResponse::extend('assertBodyLength', \Closure::bind(function (int $expected): void {
            \PHPUnit\Framework\Assert::assertSame($expected, strlen($this->getContent()));
        }, $this->make(), TestResponse::class));
        $this->assertTrue(TestResponse::hasExtension('assertBodyLength'));
        $method = $this->extensionMethodName('assertBodyLength');
        $this->make(200, [], 'abcde')->$method(5);
    }

    public function testUnregisteredExtensionThrowsQuioteException(): void
    {
        $this->expectException(QuioteException::class);
        $method = $this->extensionMethodName('assertSomethingNotRegistered');
        $this->make(200)->$method();
    }

    public function testClearExtensionsRemovesRegistration(): void
    {
        TestResponse::extend('assertAlwaysPasses', function (): void {});
        TestResponse::clearExtensions();
        $this->assertFalse(TestResponse::hasExtension('assertAlwaysPasses'));
        $this->expectException(QuioteException::class);
        $method = $this->extensionMethodName('assertAlwaysPasses');
        $this->make(200)->$method();
    }

    public function testReRegisteringExtensionOverwritesPreviousCallback(): void
    {
        TestResponse::extend('assertMarker', function (): string { return 'first'; });
        TestResponse::extend('assertMarker', function (): string { return 'second'; });
        $method = $this->extensionMethodName('assertMarker');
        $this->assertSame('second', $this->make(200)->$method());
    }
}
