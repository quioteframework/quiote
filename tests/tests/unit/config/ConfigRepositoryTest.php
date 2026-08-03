<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Config\ConfigRepository;
use Quiote\Exception\ConfigurationException;

class ConfigRepositoryTest extends TestCase
{
    public function testConstructorSeedsDirectives(): void
    {
        $repo = new ConfigRepository(['a' => 1, 'b' => 'two']);

        $this->assertSame(1, $repo->getInt('a'));
        $this->assertSame('two', $repo->getString('b'));
        $this->assertSame(['a' => 1, 'b' => 'two'], $repo->toArray());
    }

    public function testTwoRepositoriesAreIndependent(): void
    {
        $one = new ConfigRepository(['shared.key' => 'one']);
        $two = new ConfigRepository(['shared.key' => 'two']);

        $one->set('shared.key', 'changed');

        $this->assertSame('changed', $one->getString('shared.key'));
        $this->assertSame('two', $two->getString('shared.key'));
    }

    public function testGetReturnsDefaultForAnUnsetDirective(): void
    {
        $repo = new ConfigRepository();

        $this->assertNull($repo->get('nope'));
        $this->assertSame('fallback', $repo->get('nope', 'fallback'));
    }

    public function testGetStringCastsScalarsAndRejectsArrays(): void
    {
        $repo = new ConfigRepository(['int' => 7, 'bool' => true, 'arr' => ['a']]);

        $this->assertSame('7', $repo->getString('int'));
        $this->assertSame('1', $repo->getString('bool'));

        $this->expectException(ConfigurationException::class);
        $repo->getString('arr');
    }

    public function testGetStringThrowsWhenUnsetWithNoDefault(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository())->getString('missing');
    }

    public function testGetNullableStringTreatsUnsetAsMeaningful(): void
    {
        $repo = new ConfigRepository();

        $this->assertNull($repo->getNullableString('missing'));
        $this->assertSame('d', $repo->getNullableString('missing', 'd'));
    }

    public function testGetNullableStringRejectsANonScalar(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository(['arr' => []]))->getNullableString('arr');
    }

    public function testGetIntRejectsANonInt(): void
    {
        $repo = new ConfigRepository(['s' => '7']);

        $this->assertSame(3, $repo->getInt('missing', 3));

        $this->expectException(ConfigurationException::class);
        $repo->getInt('s');
    }

    public function testGetFloatWidensAnInt(): void
    {
        $repo = new ConfigRepository(['i' => 7, 'f' => 1.5]);

        $this->assertSame(7.0, $repo->getFloat('i'));
        $this->assertSame(1.5, $repo->getFloat('f'));
    }

    public function testGetFloatRejectsAString(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository(['s' => '1.5']))->getFloat('s');
    }

    public function testGetBoolDefaultsToFalseAndRejectsANonBool(): void
    {
        $repo = new ConfigRepository(['t' => true, 's' => 'yes']);

        $this->assertTrue($repo->getBool('t'));
        $this->assertFalse($repo->getBool('missing'));
        $this->assertTrue($repo->getBool('missing', true));

        $this->expectException(ConfigurationException::class);
        $repo->getBool('s');
    }

    public function testGetArrayRejectsANonArray(): void
    {
        $repo = new ConfigRepository(['a' => ['x'], 's' => 'x']);

        $this->assertSame(['x'], $repo->getArray('a'));
        $this->assertSame([], $repo->getArray('missing', []));

        $this->expectException(ConfigurationException::class);
        $repo->getArray('s');
    }

    public function testGetStringListNormalizesEveryConfiguredShape(): void
    {
        $repo = new ConfigRepository([
            'single' => 'one',
            'empty' => '',
            'list' => ['a', 'b'],
            'scalars' => [1, true],
        ]);

        $this->assertSame(['one'], $repo->getStringList('single'));
        $this->assertSame([], $repo->getStringList('empty'));
        $this->assertSame(['a', 'b'], $repo->getStringList('list'));
        $this->assertSame(['1', '1'], $repo->getStringList('scalars'));
        $this->assertSame([], $repo->getStringList('missing'));
        $this->assertSame(['d'], $repo->getStringList('missing', ['d']));
    }

    public function testGetStringListRejectsANonScalarEntry(): void
    {
        $this->expectException(ConfigurationException::class);
        (new ConfigRepository(['bad' => [['nested']]]))->getStringList('bad');
    }

    public function testSetRespectsOverwriteFalse(): void
    {
        $repo = new ConfigRepository(['k' => 'first']);

        $this->assertFalse($repo->set('k', 'second', overwrite: false));
        $this->assertSame('first', $repo->getString('k'));
        $this->assertTrue($repo->set('k', 'second'));
        $this->assertSame('second', $repo->getString('k'));
    }

    public function testAReadonlyDirectiveCannotBeOverwrittenOrRemoved(): void
    {
        $repo = new ConfigRepository();
        $this->assertTrue($repo->set('locked', 'value', readonly: true));
        $this->assertTrue($repo->isReadonly('locked'));

        $this->assertFalse($repo->set('locked', 'other'));
        $this->assertFalse($repo->remove('locked'));
        $this->assertSame('value', $repo->getString('locked'));
    }

    public function testRemoveReportsWhetherAnythingWasRemoved(): void
    {
        $repo = new ConfigRepository(['k' => 'v']);

        $this->assertTrue($repo->remove('k'));
        $this->assertFalse($repo->has('k'));
        $this->assertFalse($repo->remove('k'));
    }

    public function testHasDistinguishesAnExplicitNullFromAbsence(): void
    {
        $repo = new ConfigRepository(['null.key' => null]);

        $this->assertTrue($repo->has('null.key'));
        $this->assertFalse($repo->has('absent.key'));
    }

    /**
     * Precedence: a read-only directive is untouchable, imported data beats an existing
     * value, and an existing directive the data does not mention survives.
     */
    public function testFromArrayPrecedence(): void
    {
        $repo = new ConfigRepository(['overridden' => 'old', 'untouched' => 'kept']);
        $repo->set('locked', 'readonly value', readonly: true);

        $repo->fromArray(['overridden' => 'new', 'locked' => 'ignored', 'added' => 'added']);

        $this->assertSame('new', $repo->getString('overridden'));
        $this->assertSame('kept', $repo->getString('untouched'));
        $this->assertSame('readonly value', $repo->getString('locked'));
        $this->assertSame('added', $repo->getString('added'));
    }

    public function testClearKeepsReadonlyDirectives(): void
    {
        $repo = new ConfigRepository(['transient' => 'gone']);
        $repo->set('locked', 'stays', readonly: true);

        $repo->clear();

        $this->assertFalse($repo->has('transient'));
        $this->assertSame('stays', $repo->getString('locked'));
    }

    /**
     * A read-only directive whose value was replaced in the store no longer matches, so
     * clear() must not restore the stale read-only value.
     */
    public function testClearDropsAReadonlyDirectiveWhoseValueNoLongerMatches(): void
    {
        $repo = new ConfigRepository();
        $repo->set('locked', ['a'], readonly: true);
        $repo->fromArray(['other' => 1]);

        $repo->clear();

        $this->assertSame(['a'], $repo->getArray('locked'));
    }

    public function testResetWorkerStateKeepsReadonlyAndNamedKeys(): void
    {
        $repo = new ConfigRepository([
            'request.scoped' => 'gone',
            'keep.me' => 'kept',
        ]);
        $repo->set('locked', 'stays', readonly: true);

        $repo->resetWorkerState(['keep.me']);

        $this->assertFalse($repo->has('request.scoped'));
        $this->assertSame('kept', $repo->getString('keep.me'));
        $this->assertSame('stays', $repo->getString('locked'));
    }

    public function testResetWorkerStateWithModulesKeepsEveryModuleDirective(): void
    {
        $repo = new ConfigRepository([
            'modules.foo.enabled' => true,
            'modules.bar.enabled' => false,
            'other' => 'gone',
        ]);

        $repo->resetWorkerState(['modules']);

        $this->assertTrue($repo->has('modules.foo.enabled'));
        $this->assertTrue($repo->has('modules.bar.enabled'));
        $this->assertFalse($repo->has('other'));
    }

    public function testResetWorkerStateForAnAbsentPreserveKeyIsHarmless(): void
    {
        $repo = new ConfigRepository(['a' => 1]);

        $repo->resetWorkerState(['not.there']);

        $this->assertSame([], $repo->toArray());
    }

    /**
     * The static facade delegates to a repository that can be swapped out, so a caller can
     * install a configuration of its own and restore what was there.
     */
    public function testFacadeDelegatesToASwappableRepository(): void
    {
        $replacement = new ConfigRepository(['facade.probe' => 'from replacement']);

        $previous = Config::useRepository($replacement);
        try {
            $this->assertSame('from replacement', Config::getString('facade.probe'));
            $this->assertSame($replacement, Config::repository());

            Config::set('written.through.facade', 'value');
            $this->assertSame('value', $replacement->getString('written.through.facade'));
        } finally {
            Config::useRepository($previous);
        }

        $this->assertFalse(Config::has('facade.probe'));
    }

    public function testFacadeCreatesARepositoryOnFirstUse(): void
    {
        $previous = Config::useRepository(null);
        try {
            $repo = Config::repository();

            $this->assertInstanceOf(ConfigRepository::class, $repo);
            $this->assertSame($repo, Config::repository());
        } finally {
            Config::useRepository($previous);
        }
    }
}
