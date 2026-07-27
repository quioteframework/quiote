<?php

use Quiote\Request\Attribute\Constraint\BooleanType;
use Quiote\Request\Attribute\Constraint\Choice;
use Quiote\Request\Attribute\Constraint\Email;
use Quiote\Request\Attribute\Constraint\JsonType;
use Quiote\Request\Attribute\Constraint\NotBlank;
use Quiote\Request\Attribute\Constraint\Range;
use Quiote\Request\Attribute\Constraint\Regexp;
use Quiote\Request\Attribute\Constraint\StringLength;
use Quiote\Request\Attribute\MapRequest;
use Quiote\Request\Compiler\RequestDtoScanner;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\BooleanValidator;
use Quiote\Validator\EmailValidator;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\JsonValidator;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\RegexValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\ValidationManager;

enum ScannerFixtureStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
}

#[MapRequest]
final readonly class ScannerFixtureContactDto
{
    public function __construct(
        #[StringLength(min: 2, max: 20)] public string $title,
        #[Email] public ?string $authorEmail = null,
    ) {
    }
}

#[MapRequest]
final readonly class ScannerFixtureAllConstraintsDto
{
    public function __construct(
        #[NotBlank] public string $note,
        #[Range(min: 1, max: 10)] public int $count,
        #[Choice(values: ['red', 'green', 'blue'])] public string $color,
        #[Regexp(pattern: '/^[a-z]+$/')] public string $slug,
        #[BooleanType] public bool $flag,
        #[JsonType] public string $payload,
    ) {
    }
}

#[MapRequest]
final readonly class ScannerFixtureInferredDto
{
    public function __construct(
        public string $name,
        public int $age,
        public float $ratio,
        public bool $active,
        public ScannerFixtureStatus $status,
    ) {
    }
}

#[MapRequest]
final readonly class ScannerFixtureNoConstructorDto
{
}

class RequestDtoScannerTest extends UnitTestCase
{
    private function newManager(): ValidationManager
    {
        return $this->getContext()->createInstanceFor('validation_manager');
    }

    public function testIsMapRequestDtoDetectsAttribute(): void
    {
        $this->assertTrue(RequestDtoScanner::isMapRequestDto(ScannerFixtureContactDto::class));
        $this->assertFalse(RequestDtoScanner::isMapRequestDto(self::class));
        $this->assertFalse(RequestDtoScanner::isMapRequestDto('Nonexistent\\Class\\Name'));
    }

    public function testScanProducesPropertiesInDeclarationOrder(): void
    {
        $definition = RequestDtoScanner::scan(ScannerFixtureContactDto::class);

        $this->assertSame(ScannerFixtureContactDto::class, $definition->className);
        $this->assertCount(2, $definition->properties);
        $this->assertSame('title', $definition->properties[0]->name);
        $this->assertSame('authorEmail', $definition->properties[1]->name);
        $this->assertTrue($definition->properties[0]->isRequired());
        $this->assertFalse($definition->properties[1]->isRequired());
    }

    public function testScanRejectsClassWithoutMapRequestAttribute(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestDtoScanner::scan(self::class);
    }

    public function testScanRejectsClassWithoutConstructor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RequestDtoScanner::scan(ScannerFixtureNoConstructorDto::class);
    }

    public function testRegisterValidatorsTranslatesConstraintAttributes(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureContactDto::class, $vm, $this->getContext());

        $childs = $vm->getChilds();
        $this->assertCount(2, $childs);

        $titleValidator = null;
        $emailValidator = null;
        foreach ($childs as $child) {
            if ($child instanceof StringValidator) {
                $titleValidator = $child;
            }
            if ($child instanceof EmailValidator) {
                $emailValidator = $child;
            }
        }

        $this->assertNotNull($titleValidator);
        $this->assertSame(2, $titleValidator->getParameter('min'));
        $this->assertSame(20, $titleValidator->getParameter('max'));
        $this->assertTrue($titleValidator->getParameter('required'));

        $this->assertNotNull($emailValidator);
        $this->assertFalse($emailValidator->getParameter('required'));
    }

    public function testRegisterValidatorsTranslatesEveryConstraintType(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureAllConstraintsDto::class, $vm, $this->getContext());

        $childs = $vm->getChilds();
        $this->assertCount(6, $childs);

        $classes = array_map(static fn($child) => $child::class, $childs);
        $this->assertContains(IsNotEmptyValidator::class, $classes);
        $this->assertContains(NumberValidator::class, $classes);
        $this->assertContains(InarrayValidator::class, $classes);
        $this->assertContains(RegexValidator::class, $classes);
        $this->assertContains(BooleanValidator::class, $classes);
        $this->assertContains(JsonValidator::class, $classes);
    }

    public function testAllConstraintsEndToEndValidationPasses(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureAllConstraintsDto::class, $vm, $this->getContext());

        $request = $this->newWebRequest([
            'note' => 'hello',
            'count' => '5',
            'color' => 'green',
            'slug' => 'abc',
            'flag' => '1',
            'payload' => '{"a":1}',
        ]);

        $this->assertTrue($vm->execute($request));
    }

    public function testAllConstraintsEndToEndValidationFailsOnBadRegex(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureAllConstraintsDto::class, $vm, $this->getContext());

        $request = $this->newWebRequest([
            'note' => 'hello',
            'count' => '5',
            'color' => 'green',
            'slug' => 'NOT-LOWERCASE-123',
            'flag' => '1',
            'payload' => '{"a":1}',
        ]);

        $this->assertFalse($vm->execute($request));
    }

    public function testRegisterValidatorsInfersFromTypeWhenNoConstraintPresent(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureInferredDto::class, $vm, $this->getContext());

        $childs = $vm->getChilds();
        $this->assertCount(5, $childs);

        $classes = array_map(static fn($child) => $child::class, $childs);
        $this->assertContains(StringValidator::class, $classes);
        $this->assertContains(NumberValidator::class, $classes);
        $this->assertContains(BooleanValidator::class, $classes);
        $this->assertContains(InarrayValidator::class, $classes);
    }

    public function testInferredEnumEndToEndValidationPasses(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureInferredDto::class, $vm, $this->getContext());

        $request = $this->newWebRequest([
            'name' => 'Ada',
            'age' => '30',
            'ratio' => '0.5',
            'active' => '1',
            'status' => 'pending',
        ]);

        $this->assertTrue($vm->execute($request));
    }

    public function testInferredEnumRejectsUnknownValue(): void
    {
        $vm = $this->newManager();
        RequestDtoScanner::registerValidators(ScannerFixtureInferredDto::class, $vm, $this->getContext());

        $request = $this->newWebRequest([
            'name' => 'Ada',
            'age' => '30',
            'ratio' => '0.5',
            'active' => '1',
            'status' => 'unknown',
        ]);

        $this->assertFalse($vm->execute($request));
    }
}
