<?php

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ValidatorConfigHandler;

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
	protected function createValidationManager(?string $environment): mixed {
		$VCH = new ValidatorConfigHandler();
		$document = $this->parseConfiguration(
			Config::getString('core.config_dir') . '/tests/validators.xml',
			Config::getString('core.quiote_dir') . '/Config/xsl/validators.xsl',
			$environment
		);

		$vm = $this->getContext()->createInstanceFor('validation_manager');
		$this->includeCode($VCH->execute($document), [
			'validationManager' => $vm
		]);

		return $vm;
	}
	
	public function testTranslationDomainInheritance(): void
	{
		\Quiote\Config\Config::set('core.use_translation', true, true);
		$vm = $this->createValidationManager('test-translation-domain');
		
		$this->assertSame('test-domain-toplevel', $vm->getChild('toplevel_simple')->getParameter('translation_domain'));
		$this->assertSame('__NULL__', $vm->getChild('toplevel_reset')->getParameter('translation_domain', '__NULL__'));
		
		$this->assertSame('test-domain-toplevel', $vm->getChild('toplevel_or')->getParameter('translation_domain'));
		$this->assertSame('test-domain-toplevel', $vm->getChild('toplevel_or')->getChild('or_child')->getParameter('translation_domain'));

		$this->assertSame('test-domain-param-or', $vm->getChild('toplevel_param_or')->getParameter('translation_domain'));
		$this->assertSame('test-domain-param-or', $vm->getChild('toplevel_param_or')->getChild('param_or_child')->getParameter('translation_domain'));

		$this->assertSame('test-domain-direct-or', $vm->getChild('toplevel_direct_or')->getParameter('translation_domain'));
		$this->assertSame('test-domain-direct-nested-or', $vm->getChild('toplevel_direct_or')->getChild('direct_or_child')->getParameter('translation_domain'));
		
	}
	
	public function testErrorsDefinedByValidationDefinition(): void {
		\Quiote\Config\Config::set('core.use_translation', true, true);
		$vm = $this->createValidationManager('test-validator-definition-error-definition');
		$this->assertSame(['' => 'error-generic', 'min' => 'error-min'], $vm->getChild('standalone-empty')->getErrorMessages());
		$this->assertSame(['' => 'error-generic-validator1', 'min' => 'error-min'], $vm->getChild('standalone-with-errors-single')->getErrorMessages());
		$this->assertSame(['' => 'error-generic-validator2', 'min' => 'error-min-validator2'], $vm->getChild('standalone-with-errors-multi')->getErrorMessages());

		$this->assertSame(['' => 'error-generic-overwritten', 'min' => 'error-min-overwritten'], $vm->getChild('overwritten-empty')->getErrorMessages());
		$this->assertSame(['' => 'error-generic-validator3', 'min' => 'error-min-overwritten'], $vm->getChild('overwritten-with-errors-single')->getErrorMessages());
		$this->assertSame(['' => 'error-generic-validator4', 'min' => 'error-min-validator4'], $vm->getChild('overwritten-with-errors-multi')->getErrorMessages());
	}
	
}
?>