<?php

use PHPUnit\Framework\TestCase;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Trace\SamplerInterface;
use OpenTelemetry\SDK\Trace\SamplingResult;
use Quiote\Telemetry\ForceSampleSampler;

/**
 * `quiote.force_sample = true` must bypass the delegate sampler entirely;
 * everything else -- including a non-boolean value under the same key --
 * must defer to it untouched.
 */
class ForceSampleSamplerTest extends TestCase
{
    private function delegateReturning(SamplingResult $result): SamplerInterface
    {
        return new class ($result) implements SamplerInterface {
            public function __construct(private readonly SamplingResult $result)
            {
            }

            /**
             * @param \OpenTelemetry\SDK\Common\Attribute\AttributesInterface<mixed> $attributes
             * @param array<\OpenTelemetry\SDK\Trace\LinkInterface> $links
             */
            public function shouldSample(
                \OpenTelemetry\Context\ContextInterface $parentContext,
                string $traceId,
                string $spanName,
                int $spanKind,
                \OpenTelemetry\SDK\Common\Attribute\AttributesInterface $attributes,
                array $links,
            ): SamplingResult {
                return $this->result;
            }

            public function getDescription(): string
            {
                return 'StubDelegate';
            }
        };
    }

    public function testForceSampleAttributeBypassesTheDelegate(): void
    {
        $delegate = $this->delegateReturning(new SamplingResult(SamplingResult::DROP));
        $sampler = new ForceSampleSampler($delegate);

        $result = $sampler->shouldSample(
            Context::getRoot(),
            'trace-id',
            'span-name',
            0,
            Attributes::create(['quiote.force_sample' => true]),
            [],
        );

        $this->assertSame(SamplingResult::RECORD_AND_SAMPLE, $result->getDecision());
    }

    public function testDefersToDelegateWhenAttributeIsAbsent(): void
    {
        $delegate = $this->delegateReturning(new SamplingResult(SamplingResult::DROP));
        $sampler = new ForceSampleSampler($delegate);

        $result = $sampler->shouldSample(
            Context::getRoot(),
            'trace-id',
            'span-name',
            0,
            Attributes::create([]),
            [],
        );

        $this->assertSame(SamplingResult::DROP, $result->getDecision());
    }

    public function testDefersToDelegateWhenAttributeIsNotStrictlyTrue(): void
    {
        $delegate = $this->delegateReturning(new SamplingResult(SamplingResult::RECORD_ONLY));
        $sampler = new ForceSampleSampler($delegate);

        $result = $sampler->shouldSample(
            Context::getRoot(),
            'trace-id',
            'span-name',
            0,
            Attributes::create(['quiote.force_sample' => 'true']),
            [],
        );

        $this->assertSame(SamplingResult::RECORD_ONLY, $result->getDecision());
    }

    public function testCustomAttributeKeyIsHonored(): void
    {
        $delegate = $this->delegateReturning(new SamplingResult(SamplingResult::DROP));
        $sampler = new ForceSampleSampler($delegate, 'custom.force_key');

        $result = $sampler->shouldSample(
            Context::getRoot(),
            'trace-id',
            'span-name',
            0,
            Attributes::create(['custom.force_key' => true]),
            [],
        );

        $this->assertSame(SamplingResult::RECORD_AND_SAMPLE, $result->getDecision());
    }

    public function testGetDescriptionIncludesTheDelegateDescription(): void
    {
        $sampler = new ForceSampleSampler($this->delegateReturning(new SamplingResult(SamplingResult::DROP)));

        $this->assertSame('ForceSampleSampler{StubDelegate}', $sampler->getDescription());
    }
}
