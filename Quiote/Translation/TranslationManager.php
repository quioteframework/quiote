<?php
namespace Quiote\Translation;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Config\ConfigCache;
use Quiote\Config\APCuConfigCache;
use Quiote\Exception\QuioteException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The translation manager manages the interface between the application and the
 * current translation engine implementation
 * @since      1.0.0
 * @version    1.0.0
 */
class TranslationManager implements ResetInterface
{
	const MESSAGE = 'msg';
	const NUMBER = 'num';
	const CURRENCY = 'cur';
	const DATETIME = 'date';

	/**
	 * @var        array<string, array<string, array<int, callable>>>
	 */
	protected $translatorFilters = [];
	
	/**
	 * @var        Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        array<string, array<string, ITranslator>> An array of the translator instances for the domains.
	 */
	protected $translators = [];

	/**
	 * @var        ?QuioteLocale The current locale.
	 */
	protected $currentLocale = null;

	/**
	 * @var        ?string The original locale identifier given to this instance.
	 */
	protected $givenLocaleIdentifier = null;

	/**
	 * @var        ?string The identifier of the current locale.
	 */
	protected $currentLocaleIdentifier = null;

	/**
	 * @var        ?string The default locale identifier.
	 */
	protected $defaultLocaleIdentifier = null;

	/**
	 * @var        string The default domain which shall be used for translation.
	 */
	protected $defaultDomain = null;

	/**
	 * @var        array<string, mixed> The available locales which have been defined in the
	 *                   translation.xml config file.
	 */
	protected $availableConfigLocales = [];

	/**
	 * @var        array<string, mixed> All available locales. Just stores the info for lazyload.
	 */
	protected $availableLocales = [];

	/**
	 * @var        array<string, QuioteLocale> A cache for locale instances.
	 */
	protected $localeCache = [];

	/**
	 * @var        array<string, string> A cache for locale identifiers resolved from a string.
	 */
	protected $localeIdentifierCache = [];

    /**
     * @var        array<string, ?\DateTimeZone> A cache for the time zone instances.
     */
    protected $timeZoneCache = [];

    /** @var array<string,array{territory:?string,hasMultiple:bool}> */
    protected $timeZoneTerritoryCache = [];

    /** @var array<string,string> Canonical TZ IDs */
    protected $canonicalTimeZoneCache = [];

    /** @var array<string,array{digits:int,rounding:int}> */
    protected $currencyFractionCache = [];

    /** @var array<string,array<string, mixed>> */
    protected $territoryDataCache = [];

	/**
	 * @var        string The default time zone. If not set the timezone php 
	 *                    will be used as default.
	 */
	protected $defaultTimeZone = null;

	/**
	 * Initialize this TranslationManager.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;

		if(defined('\QUIOTE_USE_APCU_CONFIG_CACHE') && \QUIOTE_USE_APCU_CONFIG_CACHE) {
			$cacheResult = APCuConfigCache::checkConfig(Config::getString('core.config_dir') . '/translation.xml');
			if (str_starts_with($cacheResult, 'APCU:')) {
				eval('?>' . substr($cacheResult, 5));
			} else {
				include($cacheResult);
			}
		} else {
			include(ConfigCache::checkConfig(Config::getString('core.config_dir') . '/translation.xml'));
		}
		// CLDR XML loading removed; rely on ext/intl for locale, timezone, currency metadata
		$this->loadAvailableLocales();
		if($this->defaultLocaleIdentifier === null) {
			throw new QuioteException('Tried to use the translation system without a default locale and without a locale set');
		}
		$this->setLocale($this->defaultLocaleIdentifier);

		if($this->defaultTimeZone === null) {
			$this->defaultTimeZone = date_default_timezone_get();
		}
		
		if($this->defaultTimeZone === 'System/Localtime') {
			// http://trac.quiote.org/ticket/1008
			throw new QuioteException("Your default timezone is 'System/Localtime', which likely means that you're running Debian, Ubuntu or some other Linux distribution that chose to include a useless and broken patch for system timezone database lookups into their PHP package, despite this very change being declined by the PHP development team for inclusion into PHP itself.\nThis pseudo-timezone, which is not defined in the standard 'tz' database used across many operating systems and applications, works for internal PHP classes and functions because the 'real' system timezone is resolved instead, but there is no way for an application to obtain the actual timezone name that 'System/Localtime' resolves to internally - information Quiote needs to perform accurate calculations and operations on dates and times.\n\nPlease set a correct timezone name (e.g. Europe/London) via 'date.timezone' in php.ini, use date_default_timezone_set() to set it in your code, or define a default timezone for Quiote to use in translation.xml. If you have some minutes to spare, file a bug report with your operating system vendor about this problem.\n\nIf you'd like to learn more about this issue, please refer to http://trac.quiote.org/ticket/1008");
		}
	}

	/**
	 * Do any necessary startup work after initialization.
	 * This method is not called directly after initialize().
	 * @return     void
	 * @since      1.0.0
	 */
	public function startup()
	{
	}

	/**
	 * Execute the shutdown procedure.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
	}

	/**
	 * Retrieve the current application context.
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Returns the list of available locales.
	 * @return     array<string, mixed>
	 * @since      1.0.0
	 */
	public function getAvailableLocales()
	{
		return $this->availableLocales;
	}

	/**
	 * Sets the current locale.
	 * @param      string $identifier The locale identifier.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setLocale($identifier)
	{
		$this->currentLocaleIdentifier = $this->getLocaleIdentifier($identifier);
		$givenData = QuioteLocale::parseLocaleIdentifier($identifier);
		$actualData = QuioteLocale::parseLocaleIdentifier($this->currentLocaleIdentifier);
		// construct the given name from the locale from the closest match and the options that were given to the requested locale identifier
		$this->givenLocaleIdentifier = $actualData['locale_str'] . $givenData['option_str'];
	}

	/**
	 * Retrieve the current locale.
	 * @return     ?QuioteLocale The current locale.
	 * @since      1.0.0
	 */
	public function getCurrentLocale()
	{
		$this->loadCurrentLocale();
		return $this->currentLocale;
	}

	/**
	 * Retrieve the current locale identifier. This may not necessarily match 
	 * what has be given to setLocale() but instead the identifier of the closest
	 * match from the available locales.
	 * @return     string The locale identifier.
	 * @since      1.0.0
	 */
	public function getCurrentLocaleIdentifier()
	{
		return $this->currentLocaleIdentifier;
	}

	/**
	 * Retrieve the default locale.
	 * @return     QuioteLocale The current default.
	 * @since      1.0.0
	 */
	public function getDefaultLocale()
	{
		return $this->getLocale($this->getDefaultLocaleIdentifier());
	}

	/**
	 * Retrieve the default locale identifier.
	 * @return     string The default locale identifier.
	 * @since      1.0.0
	 */
	public function getDefaultLocaleIdentifier()
	{
		return $this->defaultLocaleIdentifier;
	}

	/**
	 * Sets the default domain.
	 * @param      string $domain The new default domain.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setDefaultDomain($domain)
	{
		$this->defaultDomain = $domain;
	}

	/**
	 * Retrieve the default domain.
	 * @return     string The default domain.
	 * @since      1.0.0
	 */
	public function getDefaultDomain()
	{
		return $this->defaultDomain;
	}

	/**
	 * Formats a date in the current locale.
	 * @param      mixed $date The date to be formatted.
	 * @param      ?string $domain The domain in which the date should be formatted.
	 * @param      QuioteLocale|string|null $locale The locale which should be used for formatting.
	 *                         Defaults to the currently active locale.
	 * @return     string The formatted date.
	 * @since      1.0.0
	 */
	public function _d($date, $domain = null, $locale = null)
	{
		if($domain === null) {
			$domain = $this->defaultDomain;
		}

		if($locale === null) {
			$this->loadCurrentLocale();
		} elseif(is_string($locale)) {
			$locale = $this->getLocale($locale);
		}
		
		$domainExtra = '';
		$translator = $this->getTranslators($domain, $domainExtra, self::DATETIME);

		$retval = $translator->translate($date, $domainExtra, $locale);
		
		$retval = $this->applyFilters($retval, $domain, self::DATETIME);
		
		return $retval;
	}

	/**
	 * Formats a currency amount in the current locale.
	 * @param      mixed $number The number to be formatted.
	 * @param      ?string $domain The domain in which the amount should be formatted.
	 * @param      QuioteLocale|string|null $locale The locale which should be used for formatting.
	 *                         Defaults to the currently active locale.
	 * @return     string The formatted number.
	 * @since      1.0.0
	 */
	public function _c($number, $domain = null, $locale = null)
	{
		if($domain === null) {
			$domain = $this->defaultDomain;
		}

		if($locale === null) {
			$this->loadCurrentLocale();
		} elseif(is_string($locale)) {
			$locale = $this->getLocale($locale);
		}
		
		$domainExtra = '';
		$translator = $this->getTranslators($domain, $domainExtra, self::CURRENCY);

		$retval = $translator->translate($number, $domainExtra, $locale);
		
		$retval = $this->applyFilters($retval, $domain, self::CURRENCY);
		
		return $retval;
	}

	/**
	 * Formats a number in the current locale.
	 * @param      mixed $number The number to be formatted.
	 * @param      ?string $domain The domain in which the number should be formatted.
	 * @param      QuioteLocale|string|null $locale The locale which should be used for formatting.
	 *                         Defaults to the currently active locale.
	 * @return     string The formatted number.
	 * @since      1.0.0
	 */
	public function _n($number, $domain = null, $locale = null)
	{
		if($domain === null) {
			$domain = $this->defaultDomain;
		}

		if($locale === null) {
			$this->loadCurrentLocale();
		} elseif(is_string($locale)) {
			$locale = $this->getLocale($locale);
		}
		
		$domainExtra = '';
		$translator = $this->getTranslators($domain, $domainExtra, self::NUMBER);

		$retval = $translator->translate($number, $domainExtra, $locale);
		
		$retval = $this->applyFilters($retval, $domain, self::NUMBER);
		
		return $retval;
	}

	/**
	 * Translate a message into the current locale.
	 * @param      mixed $message The message.
	 * @param      ?string $domain The domain in which the translation should be done.
	 * @param      QuioteLocale|string|null $locale The locale which should be used for formatting.
	 *                         Defaults to the currently active locale.
	 * @param      ?array<int, mixed> $parameters The parameters which should be used for sprintf on
	 *                         the translated string.
	 * @return     string The translated message.
	 * @since      1.0.0
	 */
	public function _($message, $domain = null, $locale = null, ?array $parameters = null)
	{
		if($domain === null) {
			$domain = $this->defaultDomain;
		}
		
		if($locale === null) {
			$this->loadCurrentLocale();
		} elseif(is_string($locale)) {
			$locale = $this->getLocale($locale);
		}
		
		$domainExtra = '';
		$translator = $this->getTranslators($domain, $domainExtra, self::MESSAGE);

		$retval = $translator->translate($message, $domainExtra, $locale);
		if(is_array($parameters)) {
			$retval = vsprintf($retval, $parameters);
		}
		
		$retval = $this->applyFilters($retval, $domain, self::MESSAGE);
		
		return $retval;
	}

	/**
	 * Translate a singular/plural message into the current locale.
	 * @param      string $singularMessage The message for the singular form.
	 * @param      string $pluralMessage The message for the plural form.
	 * @param      int $amount The amount for which the translation should happen.
	 * @param      ?string $domain The domain in which the translation should be done.
	 * @param      QuioteLocale|string|null $locale The locale which should be used for formatting.
	 *                         Defaults to the currently active locale.
	 * @param      ?array<int, mixed> $parameters The parameters which should be used for sprintf on
	 *                         the translated string.
	 * @return     string The translated message.
	 * @since      1.0.0
	 */
	public function __($singularMessage, $pluralMessage, $amount, $domain = null, $locale = null, ?array $parameters = null)
	{
		return $this->_([$singularMessage, $pluralMessage, $amount], $domain, $locale, $parameters);
	}

	/**
	 * Returns the translators for a given domain.
	 *
	 * Matches by progressively stripping trailing ".segment" parts off
	 * $domain until a registered translator name matches, so a nested
	 * `<translator domain="errors">` inside `<translator domain="default">`
	 * is addressed as "default.errors". $domainExtra is whatever's LEFT of
	 * $domain after that match, and it's THIS (not the matched translator
	 * name) that gets passed as the domain to ITranslator::translate() --
	 * see the caller sites (_(), _d(), _c(), _n()) below. For a top-level,
	 * non-nested translator this is always the empty string: requesting
	 * domain "default" against a translator registered as exactly
	 * "default" consumes the whole string on the first match, leaving
	 * nothing over. A translator implementation (e.g. SimpleTranslator) must
	 * key its own messages by that leftover suffix, not by its own domain
	 * name -- see SimpleTranslator::initialize()'s docblock.
	 * @param      string $domain The domain.
	 * @param      string $domainExtra The remaining part in the domain which didn't match
	 * @param      ?string $type The type of the translator
	 * @return     ($type is string ? ITranslator : array<string, ITranslator>) An array of translators for the given domain, or a single translator when a type is given
	 * @since      1.0.0
	 */
	protected function getTranslators(&$domain, &$domainExtra, $type = null)
	{
		if($domain[0] == '.') {
			$domain = $this->defaultDomain . $domain;
		}

		$domainParts = explode('.', (string) $domain);

		do {
			if(count($domainParts) == 0) {
				throw new \InvalidArgumentException(sprintf('No translator exists for the domain "%s"', $domain));
			}
			$td = implode('.', $domainParts);
			array_pop($domainParts);
		} while(!isset($this->translators[$td]) || ($type && !isset($this->translators[$td][$type])));

		$domainExtra = substr((string) $domain, strlen($td) + 1);
		$domain = $td;
		return $type ? $this->translators[$td][$type] : $this->translators[$td];
	}

	/**
	 * Returns the translators for a given domain and type. The domain can contain
	 * any extra parts which will be ignored. Will return null when no translator 
	 * is defined.
	 * @param      string $domain The domain.
	 * @param      string $type The type of the translator.
	 * @return     ?ITranslator The translator instance.
	 * @since      1.0.0
	 */
	public function getDomainTranslator($domain, $type)
	{
		try {
			$domainExtra = '';
			return $this->getTranslators($domain, $domainExtra, $type);
		} catch(\InvalidArgumentException) {
			return null;
		}
	}

	/**
	 * Loads the available locales into the instance variable (from config only).
	 */
	protected function loadAvailableLocales(): void
	{
		$this->availableLocales = $this->availableConfigLocales;
	}

	/**
	 * Lazy initialize current locale and notify translators.
	 */
	protected function loadCurrentLocale(): void
	{
		// If no locale requested yet, derive a base: prefer defaultLocaleIdentifier, else first available.
		if(!$this->givenLocaleIdentifier) {
			$base = $this->defaultLocaleIdentifier;
			if(!$base && !empty($this->availableLocales)) {
				$keys = array_keys($this->availableLocales);
				$base = $keys[0];
			}
			if($base) {
				$this->givenLocaleIdentifier = $base; // option-less
				$this->currentLocaleIdentifier = $this->getLocaleIdentifier($base);
			}
		}
		if(!$this->currentLocale || $this->currentLocale->getIdentifier() !== $this->givenLocaleIdentifier) {
			if(!$this->givenLocaleIdentifier) {
				// Still nothing resolvable: defer without throwing; callers using getLocale directly will error explicitly.
				return;
			}
			$this->currentLocale = $this->getLocale($this->givenLocaleIdentifier);
			foreach($this->translators as $translatorList) {
				foreach($translatorList as $translator) {
					$translator->localeChanged($this->currentLocale);
				}
			}
		}
	}

	/**
	 * Returns the translator filters for a given domain.
	 * @param      string $message The message.
	 * @param      string $domain The domain (w/o extra parts).
	 * @param      string $type The type.
	 * @return     string The new message.
	 * @since      1.0.0
	 */
	protected function applyFilters($message, $domain, $type = self::MESSAGE)
	{
		if(isset($this->translatorFilters[$domain][$type])) {
			foreach($this->translatorFilters[$domain][$type] as $filter) {
				$message = call_user_func($filter, $message);
			}
		}
		return $message;
	}

	/**
	 * Returns all the identifiers of the available locales which match the given 
	 * locale identifier.
	 * @param      string $identifier A locale identifier
	 * @return     array<int, string> The actual locale identifiers of the available locales.	 */
	public function getMatchingLocaleIdentifiers($identifier)
	{
		// if a locale with the given identifier doesn't exist try to find the closest matches
		if(isset($this->availableLocales[$identifier])) {
			return [$identifier];
		}
		
		$idData = QuioteLocale::parseLocaleIdentifier($identifier);
		
		$matchingLocaleIdentifiers = [];
		// iterate over all available locales
		foreach($this->availableLocales as $availableLocaleIdentifier => $availableLocale) {
			$matched = false;
			// iterate over possible properties to compare against (all given ones must match)
			foreach(['language', 'script', 'territory', 'variant'] as $propertyName) {
				// only perform check if property was in $identifier
				if(isset($idData[$propertyName])) {
					// compare against data in locale
					if($idData[$propertyName] == $availableLocale['identifierData'][$propertyName]) {
						// fine, continue with next
						$matched = true;
					} else {
						// failed, so we can bail out early and declare as non-matched
						$matched = false;
						break;
					}
				}
			}
			if($matched) {
				$matchingLocaleIdentifiers[] = $availableLocaleIdentifier;
			}
		}
		
		return $matchingLocaleIdentifiers;
	}

	/**
	 * Returns the identifier of the available locale which matches the given 
	 * locale identifier most.
	 * @param      string $identifier A locale identifier
	 * @return     string The actual locale identifier of the available locale.
	 * @since      1.0.0
	 */
	public function getLocaleIdentifier($identifier)
	{
		if(isset($this->localeIdentifierCache[$identifier])) {
			return $this->localeIdentifierCache[$identifier];
		}
		
		$matchingLocaleIdentifiers = $this->getMatchingLocaleIdentifiers($identifier);
		
		switch(count($matchingLocaleIdentifiers)) {
			case 1:
				$availableLocaleIdentifier = current($matchingLocaleIdentifiers);
				break;
			case 0:
				throw new QuioteException('Specified locale identifier ' . $identifier . ' which has no matching available locale defined');
			default:
				throw new QuioteException('Specified ambiguous locale identifier ' . $identifier . ' which has matches: ' . implode(', ', $matchingLocaleIdentifiers));
		}
		
		return $this->localeIdentifierCache[$identifier] = $availableLocaleIdentifier;
	}

	/**
	 * Returns a new Locale object from the given identifier.
	 * @param      string $identifier The locale identifier
	 * @param      bool $forceNew Force a new instance even if an identical one exists.
	 * @return     QuioteLocale The locale instance which matches the available
	 *                         locales most.
	 * @since      1.0.0
	 */
	public function getLocale($identifier, $forceNew = false)
	{
		if($identifier === '') {
			throw new QuioteException('Invalid locale identifier specified');
		}

		// Support option-only shortcut syntax starting with '@'.
		// Historical behavior required a current locale. For improved ergonomics,
		// if no current locale exists yet we fall back to the default locale identifier
		// (if defined) so calls like getLocale('@timezone=America/New_York') work
		// early during bootstrap and in isolated tests.
		if($identifier[0] === '@') {
			if($this->currentLocaleIdentifier) {
				$baseIdentifier = $this->currentLocaleIdentifier;
			} else {
				$baseIdentifier = $this->defaultLocaleIdentifier ?? null;
				if(!$baseIdentifier) {
					throw new QuioteException('Invalid locale identifier (' . $identifier . ') specified');
				}
			}
			$idData = QuioteLocale::parseLocaleIdentifier($baseIdentifier);
			$identifier = $idData['locale_str'] . $identifier; // append options
			$newIdData = QuioteLocale::parseLocaleIdentifier($identifier);
			$idData['options'] = array_merge($idData['options'], $newIdData['options']);
		} else {
			$idData = QuioteLocale::parseLocaleIdentifier($identifier);
		}

		$availableLocaleIdentifier = $this->getLocaleIdentifier($identifier);
		$availableLocale = $this->availableLocales[$availableLocaleIdentifier];

		if(str_ends_with((string) $identifier, '@')) {
			$idData['options'] = [];
		} else {
			$idData['options'] = array_merge($availableLocale['identifierData']['options'], $idData['options']);
		}

		if(($atPos = strpos((string) $identifier, '@')) !== false) {
			$identifier = $availableLocale['identifierData']['locale_str'] . substr((string) $identifier, $atPos);
		} else {
			$identifier = $availableLocale['identifier'];
		}

		if(!$forceNew && isset($this->localeCache[$identifier])) {
			return $this->localeCache[$identifier];
		}

		$data = ['locale' => []];
		foreach(['language','script','territory','variant'] as $k) {
			if(isset($availableLocale['identifierData'][$k]) && $availableLocale['identifierData'][$k] !== '') {
				$data['locale'][$k] = $availableLocale['identifierData'][$k];
			}
		}
		foreach(['calendar','currency','timezone'] as $opt) {
			if(isset($idData['options'][$opt]) && $idData['options'][$opt] !== '') {
				$data['locale'][$opt] = $idData['options'][$opt];
			}
		}

		$locale = new QuioteLocale();
		$locale->initialize($this->context, $availableLocale['parameters'], $identifier, $data);
		// NOTE: getLocale() is a pure resolver and must NOT mutate the manager's
		// current locale. It previously assigned $this->currentLocaleIdentifier here
		// "to provide a base for '@…' shortcut calls", but that made every
		// getLocale('xx_YY') / _n($n, null, 'xx_YY') / _c(...) call silently switch
		// the shared manager's active locale — leaking e.g. de_DE into later code
		// that expects the default locale's number formatting. The mutation was also
		// inconsistent (skipped entirely on the cache-hit fast path above). The '@'
		// shortcut base is taken from the locale last chosen via setLocale() (or the
		// default locale), which is the documented behavior, so nothing here needs to
		// write currentLocaleIdentifier.
		if(!$forceNew) {
			$this->localeCache[$identifier] = $locale;
		}
		return $locale;
	}

	/**
	 * Sets the default time zone.
	 * @param      \DateTimeZone|string $id The timezone identifier
	 * @return     void
	 * @since      1.0.0
	 */
	public function setDefaultTimeZone($id)
	{
		if($id instanceof \DateTimeZone) {
			$this->defaultTimeZone = $id->getName();
		} else {
			$this->defaultTimeZone = $id;
		}
	}

	/**
     * Gets the instance of the current timezone.
     * @return     ?\DateTimeZone The current timezone instance.
     * @since      1.0.0
     */
    #[\Deprecated(message: 'Superseded by TranslationManager::getDefaultTimeZone()')]
    public function getCurrentTimeZone()
	{
		return $this->getDefaultTimeZone();
	}

	/**
	 * Get the default timezone instance.
	 * @return     ?\DateTimeZone The default timezone instance.
	 * @since      1.0.0
	 */
	public function getDefaultTimeZone()
	{
		return $this->createTimeZone($this->defaultTimeZone);
	}

	/**
	 * Gets the territory id a (resolved) timezone id belongs to.
	 * @param      string $id The resolved timezone id.
	 * @param      bool $hasMultipleZones Will receive whether the territory has multiple
	 *                    time zones
	 * @return     ?string The territory identifier or null.
	 * @since      1.0.0
	 */
	public function getTimeZoneTerritory($id, &$hasMultipleZones = false)
	{
		$hasMultipleZones = false;
		if($id === '') {
			return null;
		}
		$resolved = $this->resolveTimeZoneId($id);
		if($resolved === null) {
			return null;
		}
		if(isset($this->timeZoneTerritoryCache[$resolved])) {
			$cached = $this->timeZoneTerritoryCache[$resolved];
			$hasMultipleZones = $cached['hasMultiple'];
			return $cached['territory'];
		}
		$territory = null;
		try {
			/**
			 * IntlTimeZone::getRegion() is declared as always returning a non-empty
			 * string in the bundled ext-intl stubs, but empirically (verified via
			 * direct testing) it returns `false` for a timezone id ICU doesn't
			 * recognize, e.g. IntlTimeZone::getRegion('Not/A_Real_Zone') === false.
			 * @var string|false $territory
			 */
			$territory = \IntlTimeZone::getRegion($resolved);
			if(!is_string($territory) || $territory === '') {
				$territory = null;
			}
		} catch(\Throwable) {
			$territory = null;
		}
		if($territory !== null) {
			try {
				$zones = \DateTimeZone::listIdentifiers(\DateTimeZone::PER_COUNTRY, $territory);
				if(count($zones) > 1) {
					$hasMultipleZones = true;
				}
			} catch(\Throwable) {}
		}
		$this->timeZoneTerritoryCache[$resolved] = ['territory' => $territory, 'hasMultiple' => $hasMultipleZones];
		return $territory;
	}
	
	/**
	 * Resolved the given timezone identifier to its 'real' timezone id.
	 * This provides the same functionality like 
	 * $tm->createTimeZone(id)->getResolvedId() with the difference, that using
	 * this method will not create a new timezone instance and look up the 
	 * resolved id there, but instead directly returns the resolved id by using
	 * a simple lookup.
	 * @param      \DateTimeZone|string $id The timezone id to be resolved
	 * @return     ?string The resolved timezone id
	 * @since      1.0.0
	 */
	public function resolveTimeZoneId($id)
	{
		if($id instanceof \DateTimeZone) {
			$id = $id->getName();
		}
		if($id === '') {
			return null;
		}
		if(isset($this->canonicalTimeZoneCache[$id])) {
			return $this->canonicalTimeZoneCache[$id];
		}
		$normalized = $this->normalizeOffsetTimeZoneId($id);
		$candidate = $normalized ?: $id;
		try {
			/**
			 * IntlTimeZone::getCanonicalID() is declared as always returning a
			 * string in the bundled ext-intl stubs, but empirically (verified via
			 * direct testing) it returns `false` for a timezone id ICU doesn't
			 * recognize, e.g. IntlTimeZone::getCanonicalID('Not/A_Real_Zone') === false.
			 * @var string|false $canonical
			 */
			$canonical = \IntlTimeZone::getCanonicalID($candidate, $isSystemId);
			if(is_string($canonical) && $canonical !== '') {
				return $this->canonicalTimeZoneCache[$id] = $canonical;
			}
		} catch(\Throwable) {}
		return $this->canonicalTimeZoneCache[$id] = $candidate;
	}
	

	/**
	 * Creates a new timezone instance for the given identifier.
	 * Please note that this method caches the results for each identifier, so
	 * if you plan to modify the timezones returned by this method you need to 
	 * clone them first. Alternatively you can set the cache parameter to false,
	 * but this will mean the data for this timezone will be loaded from the 
	 * hdd again.
	 * @param      mixed $id The timezone identifier
	 * @param      bool $cache Whether to use/populate the timezone instance cache.
	 * @return     ?\DateTimeZone The timezone instance for the given id.
	 * @since      1.0.0
	 */
	public function createTimeZone($id, $cache = true)
	{
		if($id instanceof \DateTimeZone) {
			return $id;
		}
		if(!is_string($id) || $id === '') {
			return null;
		}
		if($cache && isset($this->timeZoneCache[$id])) {
			return $this->timeZoneCache[$id];
		}
		$candidates = [];
		$resolved = $this->resolveTimeZoneId($id);
		if($resolved) { $candidates[] = $resolved; }
		$normalized = $this->normalizeOffsetTimeZoneId($id);
		if($normalized) { $candidates[] = $normalized; }
		$candidates[] = $id;
		foreach(array_unique($candidates) as $candidate) {
			try {
				$tz = new \DateTimeZone($candidate);
				if($cache) { $this->timeZoneCache[$id] = $tz; }
				return $tz;
			} catch(\Throwable) {}
		}
		return null;
	}

	private function normalizeOffsetTimeZoneId(string $id): ?string
	{
		if(preg_match('/^(?:GMT|UTC)?([+-])(\d{1,2})(?::?(\d{2}))?$/i', $id, $m)) {
			$h = str_pad($m[2], 2, '0', STR_PAD_LEFT);
			$min = str_pad($m[3] ?? '00', 2, '0', STR_PAD_LEFT);
			return 'GMT' . $m[1] . $h . ':' . $min;
		}
		return null;
	}

	

	/**
	 * Returns the stored information from the ldml supplemental data about a 
	 * territory.
	 * @param      string $country The uppercase 2 letter country iso code.
	 * @return     array<string, mixed> The data.
	 * @since      1.0.0
	 */
	public function getTerritoryData($country)
	{
		if($country === '') { return []; }
		$country = strtoupper($country);
		if(isset($this->territoryDataCache[$country])) { return $this->territoryDataCache[$country]; }
		$data = [];
		try {
			$cal = \IntlCalendar::createInstance(null, 'und_' . $country);
			$fd = $cal->getFirstDayOfWeek();
			$md = $cal->getMinimalDaysInFirstWeek();
			if($fd > 0) { $data['week']['firstDay'] = $fd; }
			if($md > 0) { $data['week']['minDays'] = $md; }
		} catch(\Throwable) {}
		return $this->territoryDataCache[$country] = $data;
	}

	/**
	 * Returns an array containing digits and rounding information for a currency.
	 * @param      string $currency The uppercase 3 letter currency iso code.
	 * @return     array{digits: int, rounding: int} The data.
	 * @since      1.0.0
	 */
	public function getCurrencyFraction($currency)
	{
		$currency = strtoupper((string) $currency);
		if($currency === '') { return ['digits' => 2, 'rounding' => 0]; }
		if(isset($this->currencyFractionCache[$currency])) { return $this->currencyFractionCache[$currency]; }
		$digits = 2; $rounding = 0;
		$localeIdentifier = $this->currentLocaleIdentifier ?? $this->defaultLocaleIdentifier ?? 'en_US';
		try {
			$fmt = new \NumberFormatter($localeIdentifier, \NumberFormatter::CURRENCY);
			$fmt->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $currency);
			$fd = $fmt->getAttribute(\NumberFormatter::FRACTION_DIGITS);
			if($fd >= 0) { $digits = (int) $fd; }
			$ri = $fmt->getAttribute(\NumberFormatter::ROUNDING_INCREMENT);
			if($ri > 0) { $rounding = (int) round($ri * 10 ** $digits); }
		} catch(\Throwable) {}
		return $this->currencyFractionCache[$currency] = ['digits' => $digits, 'rounding' => $rounding];
	}

	public function reset(): void {
		$this->localeCache = [];
		$this->localeIdentifierCache = [];
		$this->currentLocale = null;
		$this->currentLocaleIdentifier = null;
		$this->givenLocaleIdentifier = null;
		$this->timeZoneCache = [];
		$this->timeZoneTerritoryCache = [];
		$this->canonicalTimeZoneCache = [];
		$this->currencyFractionCache = [];
		$this->territoryDataCache = [];
	}
}