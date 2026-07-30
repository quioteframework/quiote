<?php
use Quiote\Testing\UnitTestCase;
use Quiote\Execution\ValidationResult;
use Quiote\Execution\ValidationTrace;

class ValidationResultTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // ValidationTrace lives inside ValidationService.php rather than its own
        // file, so it isn't in the PSR-4 autoload map; force-load it via its
        // sibling class so instanceof checks against it work regardless of
        // test execution order.
        class_exists(\Quiote\Execution\ValidationService::class);
    }

    public function testGetErrorsReturnsArrayFromData(): void
    {
        $result = ValidationResult::failure(['errors' => ['field' => 'required']]);
        $this->assertSame(['field' => 'required'], $result->getErrors());
    }

    public function testGetErrorsDefaultsToEmptyArrayWhenAbsent(): void
    {
        $result = ValidationResult::success();
        $this->assertSame([], $result->getErrors());
    }

    public function testGetErrorsRejectsNonArrayValue(): void
    {
        $result = new ValidationResult(false, ['errors' => 'not-an-array']);
        $this->expectException(\UnexpectedValueException::class);
        $result->getErrors();
    }

    public function testGetTraceReturnsTraceInstance(): void
    {
        $trace = new ValidationTrace('Module', 'Action', [], null);
        $result = ValidationResult::success(['trace' => $trace]);
        $this->assertSame($trace, $result->getTrace());
    }

    public function testGetTraceReturnsNullWhenAbsent(): void
    {
        $result = ValidationResult::success();
        $this->assertNull($result->getTrace());
    }

    public function testGetTraceRejectsNonTraceValue(): void
    {
        $result = new ValidationResult(true, ['trace' => 'not-a-trace']);
        $this->expectException(\UnexpectedValueException::class);
        $result->getTrace();
    }
}
