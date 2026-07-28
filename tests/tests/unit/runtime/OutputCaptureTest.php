<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Runtime\OutputCapture;

final class OutputCaptureTest extends TestCase
{
    #[After]
    public function clearPolicyConfig(): void
    {
        Config::remove('core.worker.stray_output');
    }

    public function testNothingWrittenMeansNothingCaptured(): void
    {
        $capture = new OutputCapture(OutputCapture::POLICY_APPEND);
        $capture->start();

        $this->assertSame('', $capture->finish());
    }

    public function testStrayOutputIsCaptured(): void
    {
        $capture = new OutputCapture(OutputCapture::POLICY_APPEND);
        $capture->start();
        echo 'leaked';

        $this->assertSame('leaked', $capture->finish());
    }

    public function testBuffersTheApplicationLeftOpenAreUnwoundInWriteOrder(): void
    {
        $capture = new OutputCapture(OutputCapture::POLICY_APPEND);
        $capture->start();

        // A renderer that opened its own buffer and threw before closing it: the
        // outer write happened first, so it has to come out first, even though
        // ob_get_clean() pops the innermost buffer first.
        echo 'outer-';
        ob_start();
        echo 'inner';

        $this->assertSame('outer-inner', $capture->finish());
        $this->assertSame('', $capture->finish());
    }

    public function testFinishWithoutStartIsHarmless(): void
    {
        $this->assertSame('', (new OutputCapture())->finish());
    }

    public function testStartIsIdempotentSoTheBufferStackStaysBalanced(): void
    {
        $level = ob_get_level();
        $capture = new OutputCapture(OutputCapture::POLICY_APPEND);
        $capture->start();
        $capture->start();
        echo 'once';

        $this->assertSame('once', $capture->finish());
        $this->assertSame($level, ob_get_level());
    }

    public function testAppendPolicyHandsTheOutputBackForTheResponseBody(): void
    {
        $this->assertSame('leaked', (new OutputCapture(OutputCapture::POLICY_APPEND))->apply('leaked'));
    }

    public function testDiscardPolicyDropsTheOutput(): void
    {
        $this->assertSame('', (new OutputCapture(OutputCapture::POLICY_DISCARD))->apply('leaked'));
    }

    public function testThrowPolicyFailsLoudlyAndNamesTheSetting(): void
    {
        $capture = new OutputCapture(OutputCapture::POLICY_THROW);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/core\.worker\.stray_output/');
        $capture->apply('leaked');
    }

    public function testAnEmptyCaptureIsNeverAPolicyViolation(): void
    {
        $this->assertSame('', (new OutputCapture(OutputCapture::POLICY_THROW))->apply(''));
    }

    public function testThePolicyFallsBackToConfigAndDefaultsToAppend(): void
    {
        $this->assertSame('leaked', (new OutputCapture())->apply('leaked'));

        Config::set('core.worker.stray_output', OutputCapture::POLICY_DISCARD);
        $this->assertSame('', (new OutputCapture())->apply('leaked'));
    }

    public function testALongCaptureIsTruncatedInTheDiagnosticMessage(): void
    {
        $capture = new OutputCapture(OutputCapture::POLICY_THROW);

        try {
            $capture->apply(str_repeat('x', 500));
            $this->fail('expected the throw policy to reject the captured output');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('500 byte(s)', $e->getMessage());
            $this->assertStringContainsString('...', $e->getMessage());
            $this->assertLessThan(500, strlen($e->getMessage()));
        }
    }
}
