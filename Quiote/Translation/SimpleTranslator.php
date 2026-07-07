<?php
namespace Quiote\Translation;
use Quiote\Translation\QuioteLocale;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Util\Toolkit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * SimpleTranslator defines the translator which loads the data from its
 * parameters.
 * @since      1.0.0
 * @version    1.0.0
 */
class SimpleTranslator extends BasicTranslator implements ResetInterface
{
	/**
	 * @var        array<string, mixed> The data for each domain
	 */
	protected $domainData = [];

	/**
	 * @var        array<string, mixed> The data for the currently active locale
	 */
	protected $currentData = [];

	/**
	 * @var        ?QuioteLocale The currently set locale
	 */
	protected $locale = null;

	/**
	 * Initialize this Translator.
	 *
	 * The outer key of $parameters is NOT the domain name this translator was
	 * registered under -- it's whatever's LEFT of that domain string after
	 * TranslationManager::getTranslators() has matched a translator against
	 * it (see that method's docblock). For a translator with no nested
	 * children (e.g. a bare `<translator domain="default">`), a message
	 * looked up under domain "default" consumes the ENTIRE domain string,
	 * leaving nothing over -- so this translator's own messages must be
	 * keyed by the empty string `''`, not `'default'`. Only a translator
	 * NESTED inside another (domain "default.errors" registered inside
	 * "default") sees a non-empty leftover suffix ("errors") as its outer
	 * key. Using the translator's own declared domain name as the outer key
	 * here (the natural first guess) silently returns raw, untranslated
	 * message keys with no error at all.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		parent::initialize($context);

		$domainData = [];

		foreach((array)$parameters as $domain => $locales) {
			foreach((array)$locales as $locale => $translations) {
				foreach((array)$translations as $key => $translation) {
					if(is_array($translation)) {
						$domainData[$locale][$domain][$translation['from']] = $translation['to'];
					} else {
						$domainData[$locale][$domain][$key] = $translation;
					}
				}
			}
		}

		$this->domainData = $domainData;
	}

	/**
	 * Translates a message into the defined language.
	 * @param      mixed $message The message to be translated.
	 * @param      string $domain The domain of the message.
	 * @param      ?QuioteLocale $locale The locale to which the message should be
	 *                         translated.
	 * @return     string The translated message.
	 * @since      1.0.0
	 */
	public function translate($message, $domain, ?QuioteLocale $locale = null)
	{
		$switchedLocale = $locale && $locale !== $this->locale;
		if($switchedLocale) {
			$oldCurrentData = $this->currentData;
			$oldLocale = $this->locale;
			$this->localeChanged($locale);
		}

		if(is_array($message)) {
			throw new QuioteException('The simple translator doesn\'t support pluralized input');
		} else {
			$data = $this->currentData[(string)$domain][$message] ?? $message;
		}

		if($switchedLocale) {
			$this->currentData = $oldCurrentData;
			$this->locale = $oldLocale;
		}

		return $data;

	}

	/**
	 * This method gets called by the translation manager when the default locale
	 * has been changed.
	 * @param      QuioteLocale $newLocale The new default locale.
	 * @since      1.0.0
	 */
	#[\Override]
    public function localeChanged(QuioteLocale $newLocale)
	{
		$this->locale = $newLocale;
		$this->currentData = Toolkit::getValueByKeyList($this->domainData, QuioteLocale::getLookupPath($this->locale->getIdentifier()), []);
	}

	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->domainData = [];
		$this->currentData = [];
		$this->locale = null;
	}

}

?>