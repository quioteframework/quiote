<?php

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Security\Auth\AuthorizationHeader;

class AuthorizationHeaderTest extends TestCase
{
	private function requestWith(?string $header): Psr\Http\Message\ServerRequestInterface
	{
		$request = (new Psr17Factory())->createServerRequest('GET', '/');

		return $header === null ? $request : $request->withHeader('Authorization', $header);
	}

	#[DataProvider('bearerCases')]
	public function testCredential(?string $header, ?string $expected): void
	{
		$this->assertSame($expected, AuthorizationHeader::credential($this->requestWith($header), 'Bearer'));
	}

	/** @return array<string, array{?string, ?string}> */
	public static function bearerCases(): array
	{
		return [
			'canonical'                => ['Bearer abc', 'abc'],
			'lower-cased scheme'       => ['bearer abc', 'abc'],
			'upper-cased scheme'       => ['BEARER abc', 'abc'],
			'mixed-case scheme'        => ['BeArEr abc', 'abc'],
			'extra spaces'             => ['Bearer   abc', 'abc'],
			'tab separator'            => ["Bearer\tabc", 'abc'],
			'surrounding whitespace'   => ['  Bearer abc  ', 'abc'],
			// A declared scheme with no credential is '' rather than null: the caller
			// did declare it, so it must be answered with a 401, not treated as absent.
			'bare scheme'              => ['Bearer', ''],
			'scheme with only a space' => ['Bearer ', ''],
			'different scheme'         => ['Basic abc', null],
			'scheme as a prefix only'  => ['Bearerish abc', null],
			'empty header'             => ['', null],
			'no header'                => [null, null],
		];
	}

	public function testDeclaresDistinguishesAbsentFromEmpty(): void
	{
		$this->assertTrue(AuthorizationHeader::declares($this->requestWith('Bearer'), 'Bearer'));
		$this->assertTrue(AuthorizationHeader::declares($this->requestWith('bearer abc'), 'Bearer'));
		$this->assertFalse(AuthorizationHeader::declares($this->requestWith('Basic abc'), 'Bearer'));
		$this->assertFalse(AuthorizationHeader::declares($this->requestWith(null), 'Bearer'));
	}

	public function testCredentialContainingWhitespaceIsNotTruncated(): void
	{
		// Not valid for Bearer or Basic, but truncating at the first space would be a
		// silent corruption rather than a rejection, and some schemes do use
		// parameterised credentials.
		$this->assertSame('a b c', AuthorizationHeader::credential($this->requestWith('Bearer a b c'), 'Bearer'));
	}

	public function testWorksForOtherSchemes(): void
	{
		$this->assertSame('dXNlcjpwYXNz', AuthorizationHeader::credential($this->requestWith('basic dXNlcjpwYXNz'), 'Basic'));
	}
}
