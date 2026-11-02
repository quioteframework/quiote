<?php

declare(strict_types=1);

use Quiote\Request\WebRequest;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\ArraylengthValidator;
use Quiote\Validator\IsNotEmptyValidator;
use Quiote\Validator\IssetValidator;
use Quiote\Validator\StringValidator;
use Quiote\Validator\ValidationManager;

/**
 * An empty array is "no value" to the argument precheck, the same as an absent
 * field or an empty string: a required validator rejects it, an optional one
 * skips it (so strict mode prunes it and no provides token is registered).
 * ArraylengthValidator decides presence for itself and keeps `[]` as a list of
 * length nought.
 */
final class EmptyArrayArgumentTest extends UnitTestCase
{
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
     * The minitask shape: an optional IsNotEmpty on the list provides the
     * token the per-row rules depend on.
     */
    private function minitaskManager(): ValidationManager
    {
        $manager = $this->manager();
        $manager->createValidator(IsNotEmptyValidator::class, ['tasks'], [], [
            'name' => 'taskEmptyValidator',
            'required' => false,
            'provides' => 'hasTasks',
        ]);
        $manager->createValidator(StringValidator::class, ['Description'], ['required' => 'Description is required for tasks.'], [
            'name' => 'taskDescription',
            'base' => 'tasks[]',
            'required' => true,
            'depends' => 'hasTasks',
        ]);

        return $manager;
    }

    public function testAnOptionalIsNotEmptySkipsAnEmptyArrayAndStrictModePrunesIt(): void
    {
        $manager = $this->manager();
        $manager->createValidator(IsNotEmptyValidator::class, ['tags'], [], ['required' => false]);
        $manager->createValidator(StringValidator::class, ['name'], [], ['required' => false]);

        $final = $this->validate($manager, ['tags' => [], 'name' => 'x']);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
        $this->assertFalse($final->hasParameter('tags'), 'a skipped argument is not handed to the action');
        $this->assertSame('x', $final->getParameter('name'));
    }

    public function testARequiredIsNotEmptyRejectsAnEmptyArray(): void
    {
        $manager = $this->manager();
        $manager->createValidator(IsNotEmptyValidator::class, ['tags'], ['required' => 'tags are required'], ['required' => true]);

        $this->validate($manager, ['tags' => []]);

        $this->assertSame(['tags are required'], $manager->getReport()->getErrorMessages());
    }

    public function testIsNotEmptyAcceptsANonEmptyArray(): void
    {
        $manager = $this->manager();
        $manager->createValidator(IsNotEmptyValidator::class, ['tags'], [], ['required' => true]);

        $manager->createValidator(StringValidator::class, [''], [], ['base' => 'tags[]']);

        $final = $this->validate($manager, ['tags' => ['a']]);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
        $this->assertSame(['a'], $final->getParameter('tags'));
    }

    public function testAnEmptyListProvidesNoTokenSoTheRowRulesAreSkipped(): void
    {
        $manager = $this->minitaskManager();

        $this->validate($manager, ['tasks' => []]);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
    }

    public function testAnAbsentListSkipsTheRowRulesToo(): void
    {
        $manager = $this->minitaskManager();

        $this->validate($manager, []);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
    }

    public function testANonEmptyListRunsTheRowRules(): void
    {
        $manager = $this->minitaskManager();

        $final = $this->validate($manager, ['tasks' => [['Description' => 'Paint the fence']]]);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
        $this->assertSame([['Description' => 'Paint the fence']], $final->getParameter('tasks'));
    }

    public function testARowWithoutADescriptionIsStillRejected(): void
    {
        $manager = $this->minitaskManager();

        $this->validate($manager, ['tasks' => [['Description' => '']]]);

        $this->assertSame(['Description is required for tasks.'], $manager->getReport()->getErrorMessages());
    }

    /** A named argument and the empty argument under a base agree on `[]`. */
    public function testAnEmptyArrayIsEmptyForTheNamedAndTheEmptyArgumentAlike(): void
    {
        $manager = $this->manager();
        $manager->createValidator(IsNotEmptyValidator::class, ['Tags'], ['required' => 'named'], ['base' => 'rows[]', 'required' => true]);
        $manager->createValidator(IsNotEmptyValidator::class, [''], ['required' => 'element'], ['base' => 'lists[]', 'required' => true]);

        $this->validate($manager, ['rows' => [['Tags' => []]], 'lists' => [[]]]);

        $messages = $manager->getReport()->getErrorMessages();
        sort($messages);
        $this->assertSame(['element', 'named'], $messages);
    }

    public function testArraylengthKeepsAnEmptyArrayForTheAction(): void
    {
        $manager = $this->manager();
        $manager->createValidator(ArraylengthValidator::class, ['tags'], [], ['required' => false, 'min' => 0]);

        $final = $this->validate($manager, ['tags' => []]);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
        $this->assertTrue($final->hasParameter('tags'));
        $this->assertSame([], $final->getParameter('tags'));
    }

    /**
     * The list validator vouches for the list, the element validator for each
     * element: a PATCH can send either an empty list or a full one.
     *
     * @param list<string> $tags
     */
    #[\PHPUnit\Framework\Attributes\TestWith([[]])]
    #[\PHPUnit\Framework\Attributes\TestWith([['red', 'blue']])]
    public function testArraylengthWithAnElementValidatorKeepsTheWholeList(array $tags): void
    {
        $manager = $this->manager();
        $manager->createValidator(ArraylengthValidator::class, ['tags'], [], ['required' => false, 'min' => 0]);
        $manager->createValidator(StringValidator::class, [''], [], ['base' => 'tags[]', 'required' => false]);

        $final = $this->validate($manager, ['tags' => $tags]);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
        $this->assertSame($tags, $final->getParameter('tags'));
    }

    public function testArraylengthRejectsAScalarWhereTheListShouldBe(): void
    {
        $manager = $this->manager();
        $manager->createValidator(ArraylengthValidator::class, ['tags'], ['' => 'tags must be a list'], ['required' => true, 'min' => 0]);

        $final = $this->validate($manager, ['tags' => 'red']);

        $this->assertSame(['tags must be a list'], $manager->getReport()->getErrorMessages());
        $this->assertFalse($final->hasParameter('tags'));
    }

    public function testARequiredIssetKeepsAnEmptyArray(): void
    {
        $manager = $this->manager();
        $manager->createValidator(IssetValidator::class, ['tags'], [], ['required' => true]);

        $final = $this->validate($manager, ['tags' => []]);

        $this->assertSame([], $manager->getReport()->getErrorMessages());
        $this->assertSame([], $final->getParameter('tags'));
    }
}
