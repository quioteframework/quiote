<?php

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ValidatorConfigHandler;
use Quiote\Validator\OroperatorValidator;
use Quiote\Validator\ValidationManager;
use Quiote\Validator\Compiler\Runtime\ValidatorDeclarationApplier;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class ValidatorConfigHandlerTest extends ConfigHandlerTestBase
{

	protected function getContext(): Context
	{
		if (Config::getNullableString('core.default_context') === null) {
			Config::set('core.default_context', 'web', true, true);
		}

		return Context::getInstance(Config::getNullableString('core.default_context'));
	}
	protected function createValidationManager(?string $environment): ValidationManager {
		$VCH = new ValidatorConfigHandler();
		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/validators.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/validators.xsl',
			$environment
		);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		self::assertInstanceOf(ValidationManager::class, $vm);
		// The compiled artifact is a declaration; the applier is what builds the validators from it.
		ValidatorDeclarationApplier::apply(
			$VCH->execute($document),
			$vm,
			'',
			$this->getContext(),
			Config::getString('core.config_dir') . '/tests/validators.xml'
		);

		return $vm;
	}

	public function testTranslationDomainInheritance(): void
	{
		\Quiote\Config\Config::set('core.use_translation', true, true);
		$vm = $this->createValidationManager('test-translation-domain');

		$this->assertSame('test-domain-toplevel', $vm->getChild('toplevel_simple')->getParameter('translation_domain'));
		$this->assertSame('__NULL__', $vm->getChild('toplevel_reset')->getParameter('translation_domain', '__NULL__'));

		$topLevelOr = $vm->getChild('toplevel_or');
		self::assertInstanceOf(OroperatorValidator::class, $topLevelOr);
		$this->assertSame('test-domain-toplevel', $topLevelOr->getParameter('translation_domain'));
		$this->assertSame('test-domain-toplevel', $topLevelOr->getChild('or_child')->getParameter('translation_domain'));

		$topLevelParamOr = $vm->getChild('toplevel_param_or');
		self::assertInstanceOf(OroperatorValidator::class, $topLevelParamOr);
		$this->assertSame('test-domain-param-or', $topLevelParamOr->getParameter('translation_domain'));
		$this->assertSame('test-domain-param-or', $topLevelParamOr->getChild('param_or_child')->getParameter('translation_domain'));

		$topLevelDirectOr = $vm->getChild('toplevel_direct_or');
		self::assertInstanceOf(OroperatorValidator::class, $topLevelDirectOr);
		$this->assertSame('test-domain-direct-or', $topLevelDirectOr->getParameter('translation_domain'));
		$this->assertSame('test-domain-direct-nested-or', $topLevelDirectOr->getChild('direct_or_child')->getParameter('translation_domain'));
	}

	public function testErrorsDefinedByValidationDefinition(): void {
		\Quiote\Config\Config::set('core.use_translation', true, true);
		$vm = $this->createValidationManager('test-validator-definition-error-definition');

		$standaloneEmpty = $vm->getChild('standalone-empty');
		self::assertInstanceOf(\DummyValidator::class, $standaloneEmpty);
		$this->assertSame(['' => 'error-generic', 'min' => 'error-min'], $standaloneEmpty->getErrorMessages());

		$standaloneSingle = $vm->getChild('standalone-with-errors-single');
		self::assertInstanceOf(\DummyValidator::class, $standaloneSingle);
		$this->assertSame(['' => 'error-generic-validator1', 'min' => 'error-min'], $standaloneSingle->getErrorMessages());

		$standaloneMulti = $vm->getChild('standalone-with-errors-multi');
		self::assertInstanceOf(\DummyValidator::class, $standaloneMulti);
		$this->assertSame(['' => 'error-generic-validator2', 'min' => 'error-min-validator2'], $standaloneMulti->getErrorMessages());

		$overwrittenEmpty = $vm->getChild('overwritten-empty');
		self::assertInstanceOf(\DummyValidator::class, $overwrittenEmpty);
		$this->assertSame(['' => 'error-generic-overwritten', 'min' => 'error-min-overwritten'], $overwrittenEmpty->getErrorMessages());

		$overwrittenSingle = $vm->getChild('overwritten-with-errors-single');
		self::assertInstanceOf(\DummyValidator::class, $overwrittenSingle);
		$this->assertSame(['' => 'error-generic-validator3', 'min' => 'error-min-overwritten'], $overwrittenSingle->getErrorMessages());

		$overwrittenMulti = $vm->getChild('overwritten-with-errors-multi');
		self::assertInstanceOf(\DummyValidator::class, $overwrittenMulti);
		$this->assertSame(['' => 'error-generic-validator4', 'min' => 'error-min-validator4'], $overwrittenMulti->getErrorMessages());
	}

}
?>
