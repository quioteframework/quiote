<?php

use Quiote\Exception\ConfigurationException;
use Quiote\Validator\EqualsValidator;
use Quiote\Validator\Validator;

require_once(__DIR__ . '/BaseValidatorTest.base.php');

class EqualsValidatorTest extends BaseValidatorTest
{
	public function testMatchesLiteralValue(): void
	{
		$this->doTestExecute(EqualsValidator::class, 'foo', Validator::SUCCESS, null, [], ['value' => 'foo']);
	}

	public function testDoesNotMatchLiteralValue(): void
	{
		$errors = ['' => $errorMsg = 'Values do not match'];
		$this->doTestExecute(EqualsValidator::class, 'bar', Validator::ERROR, $errorMsg, $errors, ['value' => 'foo']);
	}

	public function testMatchesAnotherParameterWhenAsparamIsSet(): void
	{
		$vm = $this->getContext()->getContainer()->get(\Quiote\Validator\ValidationManager::class);
		$validator = $vm->createValidator(EqualsValidator::class, ['value'], [], ['value' => 'reference', 'asparam' => true]);
		$rd = $this->newWebRequest(['value' => 'foo', 'reference' => 'foo']);
		$result = $validator->execute($rd);
		$this->assertSame(Validator::SUCCESS, $result);
	}

	/**
	 * "asparam" tells EqualsValidator that "value" names another request
	 * parameter to compare against, which only makes sense if "value" is a
	 * string. A non-string "value" combined with "asparam" is a
	 * misconfiguration and must fail loudly instead of being coerced.
	 */
	public function testNonStringValueWithAsparamThrows(): void
	{
		$this->expectException(ConfigurationException::class);
		$this->executeValidator(EqualsValidator::class, 'foo', [], ['value' => 123, 'asparam' => true]);
	}
}

?>
