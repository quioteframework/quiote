<?php

use Quiote\Logging\Level;
use Quiote\Logging\Log;
use Quiote\Logging\LogEvent;
use Quiote\Logging\Sink\SinkInterface;
use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\InarrayValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\JsonValidator;
use Quiote\Validator\NumberValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Validator;

class ArrayArgumentStrippingStringValidator extends StringValidator
{
    protected function normalize(string $value): string
    {
        return strip_tags($value);
    }
}

/**
 * Checks every element of the list itself: each must be an array with an
 * ItemName, so a successful run vouches for the whole subtree.
 */
class ArrayArgumentWholeListValidator extends Validator
{
    protected function validate(): bool
    {
        $rows = $this->getData($this->getArgument());
        if (!is_array($rows)) {
            $this->throwError();
            return false;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['ItemName'])) {
                $this->throwError();
                return false;
            }
        }

        return true;
    }

    protected function coversArgumentSubtree(): bool
    {
        return true;
    }
}

/**
 * Collects the warnings and errors logged during a test.
 */
class ArrayArgumentWarningSink implements SinkInterface
{
    /** @var list<LogEvent> */
    public array $events = [];

    public function isEnabled(Level $level, string $category): bool
    {
        return $level->value >= Level::Warning->value;
    }

    public function emit(LogEvent $event): void
    {
        $this->events[] = $event;
    }

    public function flush(): void
    {
    }
}

/**
 * Validators under an array base write their result back into the element
 * they validated, and strict mode leaves the action only the paths validation
 * vouched for -- inside arrays as well as at the top level.
 */
class ArrayArgumentValidationTest extends UnitTestCase
{
    private ArrayArgumentWarningSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        Log::reset();
        $this->sink = new ArrayArgumentWarningSink();
        Log::addSink($this->sink);
    }

    protected function tearDown(): void
    {
        Log::reset();
        parent::tearDown();
    }

    private function manager(): ValidationManager
    {
        $context = $this->getContext();
        $manager = $context->getContainer()->get(ValidationManager::class);
        $manager->initialize($context, ['mode' => ValidationManager::MODE_STRICT]);

        return $manager;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validate(ValidationManager $manager, array $body): WebRequest
    {
        $request = new WebRequest();
        $request->initialize($this->getContext());
        $manager->execute($request->withParsedBody($body));

        return $this->getContext()->getContainer()->get(WebRequest::class);
    }

    /**
     * @return list<string>
     */
    private function warnings(): array
    {
        return array_values(array_map(
            static fn(LogEvent $event): string => $event->renderMessage(),
            array_filter($this->sink->events, static fn(LogEvent $event): bool => $event->level === Level::Warning),
        ));
    }

    public function testSanitizedValueIsWrittenIntoEachRow(): void
    {
        $manager = $this->manager();
        $manager->createValidator(ArrayArgumentStrippingStringValidator::class, ['ItemName'], [], ['base' => 'rows[]', 'trim' => true]);

        $final = $this->validate($manager, ['rows' => [['ItemName' => ' <b>a</b> '], ['ItemName' => 'b<script>x</script>']]]);

        $this->assertSame([['ItemName' => 'a'], ['ItemName' => 'bx']], $final->getParameter('rows'));
        $this->assertNull($final->getParameter('ItemName', null), 'nothing is written to a top-level ItemName');
    }

    public function testNumberIsCoercedInPlaceAndATopLevelNameOfTheSameSpellingSurvives(): void
    {
        $manager = $this->manager();
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['base' => 'rows[]', 'type' => 'int']);
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['type' => 'int']);

        $final = $this->validate($manager, ['rows' => [['Qty' => '1'], ['Qty' => '2']], 'Qty' => '99']);

        $this->assertSame([['Qty' => 1], ['Qty' => 2]], $final->getParameter('rows'));
        $this->assertSame(99, $final->getParameter('Qty'));
    }

    public function testEmptyArgumentWritesToTheElementItself(): void
    {
        $manager = $this->manager();
        $manager->createValidator(NumberValidator::class, [''], [], ['base' => 'items[]', 'type' => 'int']);

        $final = $this->validate($manager, ['items' => ['3', '4']]);

        $this->assertSame([3, 4], $final->getParameter('items'));
    }

    public function testNonWildcardBaseWritesUnderTheBase(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['name'], [], ['base' => 'user', 'trim' => true]);

        $final = $this->validate($manager, ['user' => ['name' => ' Ada ']]);

        $this->assertSame(['name' => 'Ada'], $final->getParameter('user'));
        $this->assertNull($final->getParameter('name', null));
    }

    public function testTopLevelArgumentStillWritesBackUnderItsOwnName(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['name'], [], ['trim' => true]);

        $final = $this->validate($manager, ['name' => ' Ada ']);

        $this->assertSame('Ada', $final->getParameter('name'));
    }

    public function testUnvalidatedAndInjectedRowKeysAreRemoved(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['ItemName'], [], ['base' => 'rows[]']);

        $final = $this->validate($manager, ['rows' => [['ItemName' => 'a', 'Comment' => 'c', 'IsAdmin' => '1']]]);

        $this->assertSame([['ItemName' => 'a']], $final->getParameter('rows'));
        $this->assertNull($final->getParameter('rows[0][IsAdmin]', null));
    }

    public function testFailedRowFieldIsRemovedWhileTheOtherRowsAreCoerced(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['ItemName'], [], ['base' => 'rows[]']);
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['base' => 'rows[]', 'type' => 'int']);

        $final = $this->validate($manager, ['rows' => [['ItemName' => 'a', 'Qty' => '1'], ['ItemName' => 'b', 'Qty' => 'bad']]]);

        $this->assertSame([['ItemName' => 'a', 'Qty' => 1], ['ItemName' => 'b']], $final->getParameter('rows'));
    }

    public function testRowWithNothingValidLeftDisappears(): void
    {
        $manager = $this->manager();
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['base' => 'rows[]', 'type' => 'int']);

        $final = $this->validate($manager, ['rows' => [['Qty' => '1'], ['Qty' => 'bad', 'Comment' => 'c']]]);

        $this->assertSame([['Qty' => 1]], $final->getParameter('rows'));
    }

    public function testLaterValidatorReadsTheEarlierWriteBack(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['Code'], [], ['base' => 'rows[]', 'trim' => true]);
        $manager->createValidator(InarrayValidator::class, ['Code'], [], ['base' => 'rows[]', 'values' => 'a,b', 'sep' => ',']);

        $final = $this->validate($manager, ['rows' => [['Code' => ' a '], ['Code' => 'b ']]]);

        $this->assertSame([['Code' => 'a'], ['Code' => 'b']], $final->getParameter('rows'));
        $this->assertSame('b', $final->getParameter('rows[1][Code]'), 'the bracket-path read agrees with the nested value');
    }

    public function testSprintfExportAddsAKeyToThePostedRow(): void
    {
        $manager = $this->manager();
        $manager->createValidator(StringValidator::class, ['ItemName'], [], ['base' => 'rows[]']);
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['base' => 'rows[]', 'type' => 'int', 'export' => 'rows[%2$s][QtyInt]']);

        $final = $this->validate($manager, ['rows' => [['ItemName' => 'a', 'Qty' => '1'], ['ItemName' => 'b', 'Qty' => '2']]]);

        $this->assertSame(
            [['ItemName' => 'a', 'Qty' => '1', 'QtyInt' => 1], ['ItemName' => 'b', 'Qty' => '2', 'QtyInt' => 2]],
            $final->getParameter('rows'),
        );
        $this->assertSame([], $this->warnings());
    }

    public function testPlainExportFromAWildcardBaseWarnsOnceAndKeepsItsMeaning(): void
    {
        $manager = $this->manager();
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['base' => 'rows[]', 'type' => 'int', 'export' => 'LastQty', 'name' => 'qtyExport']);

        $final = $this->validate($manager, ['rows' => [['Qty' => '1'], ['Qty' => '2']]]);

        $this->assertSame(2, $final->getParameter('LastQty'));
        $warnings = array_values(array_filter($this->warnings(), static fn(string $m): bool => str_contains($m, 'LastQty')));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('only the last one survives', $warnings[0]);
    }

    public function testPlainExportOutsideAWildcardBaseDoesNotWarn(): void
    {
        $manager = $this->manager();
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['type' => 'int', 'export' => 'QtyInt']);

        $final = $this->validate($manager, ['Qty' => '5']);

        $this->assertSame(5, $final->getParameter('QtyInt'));
        $this->assertSame([], $this->warnings());
    }

    public function testRootOnlyValidationKeepsAnEmptyContainer(): void
    {
        $manager = $this->manager();
        $manager->createValidator(IsNotEmptyValidator::class, ['rows']);

        $final = $this->validate($manager, ['rows' => [['ItemName' => 'a']]]);

        $this->assertSame([], $final->getParameter('rows'));
    }

    public function testValidatorCoveringTheSubtreeKeepsTheContents(): void
    {
        $manager = $this->manager();
        $manager->createValidator(ArrayArgumentWholeListValidator::class, ['rows']);

        $final = $this->validate($manager, ['rows' => [['ItemName' => 'a', 'Nested' => ['x' => '1', 'empty' => []]]]]);

        $this->assertSame([['ItemName' => 'a', 'Nested' => ['x' => '1', 'empty' => []]]], $final->getParameter('rows'));
    }

    public function testValidatorCoveringTheSubtreeKeepsNothingWhenItFails(): void
    {
        $manager = $this->manager();
        $manager->createValidator(ArrayArgumentWholeListValidator::class, ['rows']);

        $final = $this->validate($manager, ['rows' => [['ItemName' => 'a'], 'not a row']]);

        $this->assertNull($final->getParameter('rows', null));
    }

    public function testDecodedJsonInARowKeepsItsContents(): void
    {
        $manager = $this->manager();
        $manager->createValidator(JsonValidator::class, ['Meta'], [], ['base' => 'rows[]']);

        $final = $this->validate($manager, ['rows' => [['Meta' => '{"a":1,"b":{"c":[]}}', 'Raw' => 'x']]]);

        $this->assertSame([['Meta' => ['a' => 1, 'b' => ['c' => []]]]], $final->getParameter('rows'));
    }

    public function testManyRowsBeyondTheAliasLimitAreStillWrittenBack(): void
    {
        $manager = $this->manager();
        $manager->createValidator(NumberValidator::class, ['Qty'], [], ['base' => 'rows[]', 'type' => 'int']);
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = ['Qty' => (string) $i, 'Junk' => 'j'];
        }

        $final = $this->validate($manager, ['rows' => $rows]);

        $expected = [];
        for ($i = 0; $i < 250; $i++) {
            $expected[] = ['Qty' => $i];
        }
        $this->assertSame($expected, $final->getParameter('rows'));
        $this->assertSame(249, $final->getParameter('rows[249][Qty]'));
    }
}
