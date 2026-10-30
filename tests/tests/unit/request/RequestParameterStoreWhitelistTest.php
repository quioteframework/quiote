<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Request\RequestParameterStore;

final class RequestParameterStoreWhitelistTest extends TestCase
{
    private static function declaring(string ...$names): RequestParameterStore
    {
        return (new RequestParameterStore())->withDeclaredParameters($names);
    }

    /** @return iterable<string, array{string}> */
    public static function keysAWildcardStandsFor(): iterable
    {
        yield 'zero' => ['groups[0][Name]'];
        yield 'positive' => ['groups[42][Name]'];
        yield 'negative' => ['groups[-1][Name]'];
        yield 'string' => ['groups[new-1][Name]'];
        yield 'the wildcard itself' => ['groups[][Name]'];
    }

    #[DataProvider('keysAWildcardStandsFor')]
    public function testAWildcardSegmentMatchesAnyKey(string $name): void
    {
        $this->assertTrue(self::declaring('groups[][Name]')->isWhitelisted($name));
    }

    /** @return iterable<string, array{string}> */
    public static function pathsAWildcardDoesNotCover(): iterable
    {
        yield 'another field' => ['groups[0][IsAdmin]'];
        yield 'a deeper path' => ['groups[0][Name][x]'];
        yield 'a shallower path' => ['groups[0]'];
        yield 'another root' => ['other[0][Name]'];
    }

    #[DataProvider('pathsAWildcardDoesNotCover')]
    public function testAWildcardCoversNothingBeyondItsOwnShape(string $name): void
    {
        $this->assertFalse(self::declaring('groups[][Name]')->isWhitelisted($name));
    }

    public function testANamedSegmentIsNotAWildcard(): void
    {
        $store = self::declaring('user[address][city]');

        $this->assertTrue($store->isWhitelisted('user[address][city]'));
        $this->assertFalse($store->isWhitelisted('user[billing][city]'));
        $this->assertFalse($store->isWhitelisted('user[0][city]'));
    }

    public function testANumberedDeclarationOnlyCoversThatNumber(): void
    {
        $store = self::declaring('rows[1][]');

        $this->assertTrue($store->isWhitelisted('rows[1][-3]'));
        $this->assertFalse($store->isWhitelisted('rows[2][-3]'));
    }

    public function testNestedWildcardsEachMatchAnyKey(): void
    {
        $this->assertTrue(self::declaring('matrix[][][v]')->isWhitelisted('matrix[-1][b][v]'));
    }

    public function testAnUnparsableNameIsNotWhitelisted(): void
    {
        $this->assertFalse(self::declaring('groups[][Name]')->isWhitelisted('groups[0]]Name['));
    }

    public function testAPlainNameIsOnlyWhitelistedVerbatim(): void
    {
        $store = self::declaring('groups[][Name]', 'title');

        $this->assertTrue($store->isWhitelisted('title'));
        $this->assertFalse($store->isWhitelisted('Name'));
    }
}
