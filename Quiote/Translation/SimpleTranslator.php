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
	 * $parameters is normally domain-nested (`domain => locale =>
	 * key/translation`), where the outer key is NOT the domain name this
	 * translator was registered under -- it's whatever's LEFT of that domain
	 * string after TranslationManager::getTranslators() has matched a
	 * translator against it (see that method's docblock). A translator
	 * nested inside another (domain "default.errors" registered inside
	 * "default") sees the non-empty leftover suffix ("errors") as its outer
	 * key, mirroring GettextTranslator's own `text_domains` sub-catalogs
	 * (e.g. the legacy `_('...', 'default.errors')` convention).
	 *
	 * A translator with no such sub-domains of its own -- the common case --
	 * has nothing meaningful to put in that outer position: requesting its
	 * own registered domain consumes the whole string, leaving an empty
	 * leftover. Rather than force config authors to write out `'' =>
	 * [...]` for that (a correct but easy-to-mistype, easy-to-forget
	 * degenerate case), a plain `locale => key/translation` shape -- no
	 * domain-key wrapper at all -- is also accepted and treated as if it had
	 * been wrapped in a single `''` domain. Auto-detected: if every
	 * top-level key parses as a valid {@see QuioteLocale} identifier, the
	 * shape is flat; otherwise it's domain-nested (a domain name like
	 * "errors" or the empty string never parses as a locale, so genuine
	 * multi-domain configs are unaffected).
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		parent::initialize($context);

		if($this->isFlatLocaleShape($parameters)) {
			$parameters = ['' => $parameters];
		}

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
	 * Whether $parameters is a flat `locale => key/translation` shape (no
	 * domain-key wrapper) rather than the domain-nested `domain => locale =>
	 * key/translation` shape -- see initialize()'s docblock. True only when
	 * $parameters is non-empty and every top-level key parses as a valid
	 * locale identifier; a domain name (including `''`) never does.
	 * @param      array<string, mixed> $parameters
	 */
	private function isFlatLocaleShape(array $parameters): bool
	{
		if($parameters === []) {
			return false;
		}
		foreach(array_keys($parameters) as $key) {
			try {
				QuioteLocale::parseLocaleIdentifier($key);
			} catch(\Throwable) {
				return false;
			}
		}
		return true;
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