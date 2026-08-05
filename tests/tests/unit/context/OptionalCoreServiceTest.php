<?php

use Quiote\Context;
use Quiote\Database\DatabaseManager;
use Quiote\Exception\ConfigurationException;
use Quiote\Testing\Attributes\IsolationEnvironment;
use Quiote\Testing\PhpUnitTestCase;
use Quiote\Translation\TranslationManager;

/**
 * The translation manager and the database manager are optional components, and how their *absence*
 * is bound is the subject here.
 *
 * Leaving the binding out is not a safe way to say "not configured": both classes are instantiable
 * with no required constructor arguments, so the container would autowire a brand-new one -- a
 * translation manager with no locales, a database manager with no connections -- and the consumer
 * would have no way to tell. So the binding always exists, and when the component is absent it
 * explains which configuration would have declared it.
 *
 * Each test arranges the absent case the way a worker rebuild reaches it -- drop the component and
 * re-run the registration -- rather than relying on what the sandbox application happens to configure.
 * The configured path is covered wherever a suite installs a manager and resolves it.
 */
#[IsolationEnvironment('testing')]
class OptionalCoreServiceTest extends PhpUnitTestCase
{
	/**
	 * Put the context into the state it is in when the configuration declared no such component: the
	 * property empty, and the container bindings built from that.
	 *
	 * Through the real registration method rather than by binding something here, because *what* it
	 * binds for an absent component is the whole subject.
	 */
	private function withoutComponent(string $profile, string $property, string $class): Context
	{
		$context = Context::getInstance($profile);
		(new ReflectionObject($context))->getProperty($property)->setValue($context, null);
		(new ReflectionMethod(Context::class, 'registerCoreServicesInContainer'))->invoke($context);
		// The instance bound before it was dropped is still memoized against the class name; the
		// rebinding replaces the definition, not the resolution.
		$context->getContainer()->forgetResolved($class);

		return $context;
	}

	public function testAnAbsentTranslationManagerIsBoundToAnExplanation(): void
	{
		$container = $this
			->withoutComponent('opt-core::no-translation', 'translationManager', TranslationManager::class)
			->getContainer();

		$this->assertTrue(
			$container->has(TranslationManager::class),
			'the binding exists whether or not the component does',
		);

		try {
			$container->get(TranslationManager::class);
			$this->fail('resolving an unconfigured translation manager must not answer an empty one');
		} catch (ConfigurationException $e) {
			$this->assertStringContainsString('translation_manager', $e->getMessage());
			$this->assertStringContainsString('opt-core::no-translation', $e->getMessage());
		}
	}

	/**
	 * The role name has to fail the same way. An application resolving `'translationManager'` and one
	 * resolving the class name are asking the same question.
	 */
	public function testTheRoleNameFailsTheSameWay(): void
	{
		$container = $this
			->withoutComponent('opt-core::no-translation-role', 'translationManager', TranslationManager::class)
			->getContainer();

		$this->expectException(ConfigurationException::class);
		$container->get('translationManager');
	}

	/**
	 * `tryGet()` is how an optional dependency asks, and it still answers null -- the explanation is
	 * for the caller who declared the component as required, not for the one who did not.
	 */
	public function testTryGetStillAnswersNull(): void
	{
		$container = $this
			->withoutComponent('opt-core::try-get', 'translationManager', TranslationManager::class)
			->getContainer();

		$this->assertNull($container->tryGet(TranslationManager::class));
	}

	public function testAnAbsentDatabaseManagerIsBoundToAnExplanation(): void
	{
		$container = $this
			->withoutComponent('opt-core::no-database', 'databaseManager', DatabaseManager::class)
			->getContainer();

		$this->assertTrue($container->has(DatabaseManager::class));

		try {
			$container->get(DatabaseManager::class);
			$this->fail('resolving an unconfigured database manager must not answer an empty one');
		} catch (ConfigurationException $e) {
			$this->assertStringContainsString('database_manager', $e->getMessage());
		}
	}

	/**
	 * Installing one replaces the explanation, which is what a test helper and an application-side
	 * rebinding both rely on.
	 */
	public function testInstallingOneReplacesTheExplanation(): void
	{
		$context = $this->withoutComponent('opt-core::installed', 'translationManager', TranslationManager::class);
		$container = $context->getContainer();

		$container->setFactory(
			TranslationManager::class,
			static function () use ($context): TranslationManager {
				$manager = new TranslationManager();
				$manager->initialize($context, []);

				return $manager;
			},
		);

		$this->assertInstanceOf(TranslationManager::class, $container->get(TranslationManager::class));
	}
}
