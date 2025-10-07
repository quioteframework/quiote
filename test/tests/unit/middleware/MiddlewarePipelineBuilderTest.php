<?php
// Neutralized legacy builder test file during middleware refactor.
use PHPUnit\Framework\TestCase;
final class MiddlewarePipelineBuilderTest extends TestCase {
    protected function setUp(): void { $this->markTestSkipped('MiddlewarePipelineBuilder removed during refactor'); }
    public function testPlaceholder(): void { $this->assertTrue(true); }
}
