<?php
namespace Quiote\Translation;
use Quiote\Translation\QuioteLocale;

use Quiote\Context;

/**
 * ITranslator defines the interface for different translator 
 * implementations (like gettext, XLIFF, ...)
 * @since      1.0.0
 * @version    1.0.0
 */
interface ITranslator
{
	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public function getContext();

	/**
	 * Initialize this Translator.
	 * @param      Context The current application context.
	 * @param      array        An associative array of initialization parameters
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = []);

	/**
	 * Translates a message into the defined language.
	 * @param      mixed       The message to be translated.
	 * @param      string      The domain of the message.
	 * @param      ?Locale The locale to which the message should be 
	 *                         translated.
	 * @return     string The translated message.
	 * @since      1.0.0
	 */
	public function translate($message, $domain, ?QuioteLocale $locale = null);

	/**
	 * This method gets called by the translation manager when the default locale
	 * has been changed.
	 * @param      Locale The new default locale.
	 * @since      1.0.0
	 */
	public function localeChanged($newLocale);

}

?>