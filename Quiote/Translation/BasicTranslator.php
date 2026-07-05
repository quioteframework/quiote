<?php
namespace Quiote\Translation;

use Quiote\Context;
use Quiote\Translation\QuioteLocale;
use Symfony\Contracts\Service\ResetInterface;

/**
 * BasicTranslator defines some base functions for all translators.
 * @since      1.0.0
 * @version    1.0.0
 */
abstract class BasicTranslator implements ITranslator, ResetInterface
{
	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * Retrieve the current application context.
	 * @return     ?Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Initialize this Translator.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
	}

	/**
	 * This method gets called by the translation manager when the default locale
	 * has been changed.
	 * @param      QuioteLocale $newLocale The new default locale.
	 * @since      1.0.0
	 */
	public function localeChanged(QuioteLocale $newLocale)
	{
	}

	public function reset() : void
	{
		$this->context = null;
	}
}

?>