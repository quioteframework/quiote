<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Util\AttributeHolder;
use Quiote\Util\FormPopulationConfig;

class FormPopulationConfigTest extends PhpUnitTestCase
{
	private function makeLegacyRequest(): AttributeHolder
	{
		return new class extends AttributeHolder {
		};
	}

	public function testGetReturnsEmptyArrayWhenNoConfigWasStored(): void
	{
		$request = $this->makeLegacyRequest();

		$this->assertSame([], FormPopulationConfig::get($request));
	}

	public function testSeedThenGetRoundTripsOnALegacyAttributeHolder(): void
	{
		$request = $this->makeLegacyRequest();

		$updated = FormPopulationConfig::seed($request, ['force_request_uri' => '/foo']);

		$this->assertSame($request, $updated);
		$this->assertSame(['force_request_uri' => '/foo'], FormPopulationConfig::get($request));
	}

	public function testMergeOverridesExistingScopedValues(): void
	{
		$request = $this->makeLegacyRequest();
		FormPopulationConfig::seed($request, ['force_request_uri' => '/foo']);

		FormPopulationConfig::merge($request, ['force_request_uri' => '/bar']);

		$this->assertSame('/bar', FormPopulationConfig::getScopedValue($request, 'force_request_uri'));
	}

	public function testGetScopedValueReturnsDefaultWhenMissing(): void
	{
		$request = $this->makeLegacyRequest();

		$this->assertSame('fallback', FormPopulationConfig::getScopedValue($request, 'missing_key', 'fallback'));
	}

	/**
	 * A class-string passes method_exists() checks (it reflects on the class
	 * declaration, not an instance), so a naive "method_exists($request, ...)"
	 * guard is not sufficient to prove $request is safe to call methods on.
	 * get()/seed()/merge()/store() must not attempt to invoke instance methods
	 * on a non-object value, even when that value happens to name a class that
	 * declares matching methods.
	 */
	public function testGetReturnsEmptyArrayForNonObjectRequestThatNamesAClassWithMatchingMethods(): void
	{
		$this->assertSame([], FormPopulationConfig::get(AttributeHolder::class));
	}

	public function testSeedReturnsRequestUnchangedForNonObjectRequest(): void
	{
		$this->assertSame(AttributeHolder::class, FormPopulationConfig::seed(AttributeHolder::class, ['foo' => 'bar']));
	}

	public function testMergeReturnsRequestUnchangedForNonObjectRequest(): void
	{
		$this->assertSame(AttributeHolder::class, FormPopulationConfig::merge(AttributeHolder::class, ['foo' => 'bar']));
	}

	public function testGetReturnsEmptyArrayForNullRequest(): void
	{
		$this->assertSame([], FormPopulationConfig::get(null));
	}

	public function testSeedReturnsNullUnchangedForNullRequest(): void
	{
		$this->assertNull(FormPopulationConfig::seed(null, ['foo' => 'bar']));
	}
}
