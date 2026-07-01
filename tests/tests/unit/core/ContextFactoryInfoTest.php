<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Context;
use Quiote\Exception\QuioteException;

/**
 * Tests for Context factory info normalization and error paths.
 */
class ContextFactoryInfoTest extends UnitTestCase
{
    public function testGetFactoryInfoNormalization(): void
    {
        $ctx = $this->getContext();
        // Synthesize a legacy style info
    // Use existing web response implementation class name (simplest stub present in factories normally)
    $responseClass = \Quiote\Response\WebResponse::class; // base class acceptable for initialization
    $ctx->setFactoryInfo('response', ['class' => $responseClass, 'parameters' => ['x' => 1]]);
        $info = $ctx->getFactoryInfo('response');
        $this->assertArrayHasKey('class', $info);
        $this->assertArrayHasKey('parameters', $info);
    $this->assertSame($responseClass, $info['class']);

        // Now set nested style and ensure normalization still returns inner array
        $ctx->setFactoryInfo('validation_manager', [
            'factory_info' => ['class' => \Quiote\Validator\ValidationManager::class, 'parameters' => ['mode' => 'strict']],
            'other' => 'ignored'
        ]);
        $info2 = $ctx->getFactoryInfo('validation_manager');
        $this->assertSame(\Quiote\Validator\ValidationManager::class, $info2['class']);
        $this->assertSame('strict', $info2['parameters']['mode']);
    }

    public function testCreateInstanceForThrowsOnMissing(): void
    {
        $ctx = $this->getContext();
        $this->expectException(QuioteException::class);
        $ctx->createInstanceFor('no_such_factory');
    }
}
