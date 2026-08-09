<?php
namespace Quiote\Translation;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Util\ParameterHolder;
use Locale;
use NumberFormatter;
use Symfony\Contracts\Service\ResetInterface;
use function is_array;
use function is_string;

/**
 * Represents a single locale: its identifier plus the language/territory/
 * script/variant and the calendar/currency/timezone options carried in the
 * identifier's '@key=value' suffix. All CLDR-derived metadata (calendar names,
 * number symbols, display names, …) is served directly from ext/intl by the
 * formatters and the {@see TranslationManager}; this class only resolves the
 * identifier and its options.
 * @since      1.0.0
 * @version    1.0.0
 */
class QuioteLocale extends ParameterHolder implements ResetInterface
{
	/**
	 * Compiled locale-identifier pattern. Built once as a constant rather than
	 * re-concatenated from its base/options sub-patterns on every
	 * parseLocaleIdentifier() call. The forward assertion in the script group
	 * stops it from matching the first character of the territory.
	 */
	private const string LOCALE_IDENTIFIER_REGEX =
		'#^((?P<language>[^_@]{2,3})(?:_(?P<script>[^_@](?=@|_|$)|[^_@]{4,}))?(?:_(?P<territory>[^_@]{2,3}))?(?:_(?P<variant>[^@]+))?)(@(?P<options>.*))?$#';

	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        array<string, mixed> The data.
	 */
	protected $data = [];

	/**
	 * @var        ?string The identifier of this locale.
	 */
	protected $identifier = null;

	/**
	 * Returns the locale option string containing the timezone option set
	 * to the timezone of this calendar.
	* @param      \DateTimeInterface|\DateTimeZone|string|int $item The item to determine the timezone
	 *                                        from
	 * @param      string $prefix The prefix which will be applied to the timezone option
	 *                    string. Use ';' here if you intend to use several
	 *                    locale options and append the result of this method
	 *                    to your locale string.
	 * @return     string Returns an empty string (NOT containing the $prefix)
	 *                    if $item is invalid or no timezone could be determined
	 * @since      1.0.0
	 */
	public static function getTimeZoneOptionString($item, $prefix = '@')
	{
		$tzId = '';
		if($item instanceof \DateTimeInterface) {
			$tzId = $item->getTimezone()->getName();
		} elseif($item instanceof \DateTimeZone) {
			$tzId = $item->getName();
		} elseif(is_string($item) && $item !== '') {
			$tzId = $item;
		} elseif(is_int($item)) {
			$tzId = 'UTC';
		}

		if($tzId && preg_match('/^[+-][0-9:]+$/', $tzId)) {
			$tzId = 'GMT' . $tzId;
		}

		if($tzId) {
			return $prefix . 'timezone=' . $tzId;
		} else {
			return '';
		}
	}

	/**
	 * Initialize this Locale.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @param      string $identifier The identifier of the locale
	 * @param      array<string, mixed> $data The locale data.
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [], $identifier = null, array $data = [])
	{
		$this->context = $context;
		$this->parameters = $parameters;

		$this->identifier = $identifier;
		$this->data = $data;
	}

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
	 * Returns the identifier of this locale
	 * @return     ?string The identifier.
	 * @since      1.0.0
	 */
	public function getIdentifier()
	{
		return $this->identifier;
	}

	////////////////////////////// Locale data //////////////////////////////////

	/**
	 * @return     ?string The language of this locale.
	 */
	public function getLocaleLanguage()
	{
		if(isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['language'])) {
			return is_string($this->data['locale']['language']) ? $this->data['locale']['language'] : null;
		}

		if(class_exists(Locale::class)) {
			try {
				return Locale::getPrimaryLanguage($this->getBaseLocaleIdentifier()) ?: null;
			} catch(\Throwable $e) {
				$this->logIcuProbeFailure('getPrimaryLanguage', $e);
			}
		}

		return null;
	}

	/**
	 * @return     ?string The territory of this locale.
	 */
	public function getLocaleTerritory()
	{
		if(isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['territory'])) {
			return is_string($this->data['locale']['territory']) ? $this->data['locale']['territory'] : null;
		}

		if(class_exists(Locale::class)) {
			try {
				$region = Locale::getRegion($this->getBaseLocaleIdentifier());
				return $region !== '' ? $region : null;
			} catch(\Throwable $e) {
				$this->logIcuProbeFailure('getRegion', $e);
			}
		}

		return null;
	}

	/**
	 * @return     ?string The script of this locale.
	 */
	public function getLocaleScript()
	{
		if(isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['script'])) {
			return is_string($this->data['locale']['script']) ? $this->data['locale']['script'] : null;
		}

		if(class_exists(Locale::class)) {
			try {
				$script = Locale::getScript($this->getBaseLocaleIdentifier());
				if($script === '') {
					$parts = $this->getParsedLocaleParts();
					$script = isset($parts['script']) && is_string($parts['script']) ? $parts['script'] : '';
				}
				return $script !== '' ? $script : null;
			} catch(\Throwable $e) {
				$this->logIcuProbeFailure('getScript', $e);
			}
		}

		return null;
	}

	/**
	 * @return     ?string The variant of this locale.
	 */
	public function getLocaleVariant()
	{
		if(isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['variant'])) {
			return is_string($this->data['locale']['variant']) ? $this->data['locale']['variant'] : null;
		}

		try {
			$parts = $this->getParsedLocaleParts();
			$variants = [];
			foreach($parts as $key => $value) {
				if(str_starts_with((string) $key, 'variant') && is_string($value) && $value !== '') {
					$variants[] = $value;
				}
			}
			if($variants) {
				return implode('_', $variants);
			}
		} catch(\Throwable $e) {
			$this->logIcuProbeFailure('parseLocale/variants', $e);
		}

		return null;
	}

	/**
	 * @return     ?string The currency code of this locale.
	 */
	public function getLocaleCurrency()
	{
		if(isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['currency'])) {
			return is_string($this->data['locale']['currency']) ? $this->data['locale']['currency'] : null;
		}
		if(isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['currencyOverride'])) {
			return is_string($this->data['locale']['currencyOverride']) ? $this->data['locale']['currencyOverride'] : null;
		}
		if(isset($this->parameters['currency'])) {
			return is_string($this->parameters['currency']) ? $this->parameters['currency'] : null;
		}

		if(class_exists(NumberFormatter::class)) {
			try {
				$formatter = new NumberFormatter($this->getBaseLocaleIdentifier(), NumberFormatter::CURRENCY);
				$code = $formatter->getTextAttribute(NumberFormatter::CURRENCY_CODE);
				if($code !== '') {
					return $code;
				}
			} catch(\Throwable $e) {
				$this->logIcuProbeFailure('NumberFormatter/CURRENCY_CODE', $e);
			}
		}

		return null;
	}

	/**
	 * @return     ?string The calendar identifier of this locale.
	 */
	public function getLocaleCalendar()
	{
		if (isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['calendar'])) {
			return is_string($this->data['locale']['calendar']) ? $this->data['locale']['calendar'] : null;
		}
		if (isset($this->parameters['calendar']) && is_string($this->parameters['calendar'])) {
			return $this->parameters['calendar'];
		}
		return null;
	}

	/**
	 * @return     ?string The timezone identifier of this locale.
	 */
	public function getLocaleTimeZone()
	{
		if (isset($this->data['locale']) && is_array($this->data['locale']) && isset($this->data['locale']['timezone'])) {
			return is_string($this->data['locale']['timezone']) ? $this->data['locale']['timezone'] : null;
		}
		return isset($this->parameters['timezone']) && is_string($this->parameters['timezone']) ? $this->parameters['timezone'] : null;
	}

	/**
	 * The name of a currency as this locale writes it: `EUR` as "Euro" in `en`, "euro" in `fi`.
	 *
	 * Read from ICU's own currency data, which is where the answer lives and which ext/intl exposes
	 * directly -- so this needs no data of its own and no table to maintain. ICU falls back through the
	 * locale's parents itself, so a locale with no currency names of its own still answers.
	 *
	 * Declared data wins, as everywhere else in this class: a caller that supplied
	 * `numbers.currencies.EUR.displayName` gets that back rather than ICU's.
	 *
	 * @param      string $code An ISO 4217 code, e.g. `EUR`.
	 * @return     ?string The localized name, or null when this locale's data does not name the code.
	 * @since      4.0.0
	 */
	public function getCurrencyDisplayName(string $code): ?string
	{
		if(isset($this->data['numbers']) && is_array($this->data['numbers'])
			&& isset($this->data['numbers']['currencies']) && is_array($this->data['numbers']['currencies'])
			&& isset($this->data['numbers']['currencies'][$code])) {
			$declared = $this->data['numbers']['currencies'][$code];
			if(is_string($declared)) {
				return $declared;
			}
			if(is_array($declared) && isset($declared['displayName']) && is_string($declared['displayName'])) {
				return $declared['displayName'];
			}
		}

		if(!class_exists(\ResourceBundle::class)) {
			return null;
		}

		// Walked explicitly rather than left to ResourceBundle's own fallback, which resolves the
		// *bundle* and not the individual key: `en_GB` has a Currencies table of its own -- it renames a
		// few -- and no EUR entry in it, so asking it alone answers nothing where `en` answers "Euro".
		foreach($this->getCurrencyLookupLocales() as $candidate) {
			try {
				$bundle = \ResourceBundle::create($candidate, 'ICUDATA-curr');
				if($bundle === null) {
					continue;
				}

				$currencies = $bundle['Currencies'];
				if(!$currencies instanceof \ResourceBundle) {
					continue;
				}

				// ICU stores each currency as [symbol, display name, ...]; the name is the second entry.
				$entry = $currencies[$code];
				if(!$entry instanceof \ResourceBundle) {
					continue;
				}

				$name = $entry[1];
				if(is_string($name) && $name !== '') {
					return $name;
				}
			} catch(\Throwable $e) {
				$this->logIcuProbeFailure('getCurrencyDisplayName', $e);
			}
		}

		return null;
	}

	/**
	 * This locale and its parents, most specific first: `en_GB`, `en`, `root`.
	 *
	 * @return     array<int, string>
	 * @since      4.0.0
	 */
	private function getCurrencyLookupLocales(): array
	{
		$identifier = $this->getBaseLocaleIdentifier();
		$candidates = [];

		while($identifier !== '') {
			$candidates[] = $identifier;
			$cut = strrpos($identifier, '_');
			$identifier = $cut === false ? '' : substr($identifier, 0, $cut);
		}

		$candidates[] = 'root';

		return $candidates;
	}

	/**
	 * Scripts written right to left, by ISO 15924 code.
	 *
	 * A script list rather than a language list, because the script is what decides direction and a
	 * language can be written in more than one: `az_Cyrl` and `az_Latn` read left to right while
	 * `az_Arab` does not, and a language list has to guess which one a bare `az` means. ICU answers
	 * that question -- see {@see getCharacterOrientation()}.
	 *
	 * @var        array<int, string>
	 * @since      4.0.0
	 */
	private const array RIGHT_TO_LEFT_SCRIPTS = [
		'Adlm', 'Arab', 'Aran', 'Armi', 'Avst', 'Cprt', 'Egyp', 'Elym', 'Hatr', 'Hebr', 'Hung',
		'Khar', 'Lydi', 'Mand', 'Mani', 'Mend', 'Merc', 'Mero', 'Narb', 'Nbat', 'Nkoo', 'Orkh',
		'Palm', 'Phli', 'Phlp', 'Phnx', 'Prti', 'Rohg', 'Samr', 'Sarb', 'Sogd', 'Sogo', 'Syrc',
		'Thaa', 'Yezi',
	];

	/**
	 * Which way this locale's text runs: `left-to-right` or `right-to-left`.
	 *
	 * The answer a template needs to decide `dir="rtl"`, which is the only thing anything has ever
	 * asked this for.
	 *
	 * Resolved from the locale's *script*, because that is what decides direction -- and asked of ICU
	 * rather than of the identifier, since a bare `ar` or `ur` names no script. `addLikelySubtags()`
	 * is how ICU says which script a language is written in by default, so `ur` resolves through
	 * `ur_Arab_PK` and an explicit `az_Latn` is taken at its word.
	 *
	 * Left-to-right is the answer when the script cannot be determined at all. It is the direction of
	 * the overwhelming majority of locales, and a page laid out left to right for a locale nobody could
	 * identify is a smaller wrong than one laid out backwards.
	 *
	 * @return     string `left-to-right` or `right-to-left`.
	 * @since      4.0.0
	 */
	public function getCharacterOrientation(): string
	{
		$script = $this->getLocaleScript();

		if($script === null && class_exists(Locale::class)) {
			try {
				$likely = Locale::addLikelySubtags($this->getBaseLocaleIdentifier());
				if(is_string($likely) && $likely !== '') {
					$resolved = Locale::getScript($likely);
					$script = is_string($resolved) && $resolved !== '' ? $resolved : null;
				}
			} catch(\Throwable $e) {
				$this->logIcuProbeFailure('addLikelySubtags', $e);
			}
		}

		return $script !== null && in_array($script, self::RIGHT_TO_LEFT_SCRIPTS, true)
			? 'right-to-left'
			: 'left-to-right';
	}

	private function getBaseLocaleIdentifier(): string
	{
		$identifier = (string) $this->identifier;
		$pos = strpos($identifier, '@');
		return $pos === false ? $identifier : substr($identifier, 0, $pos);
	}

	/**
	 * @return     array<string, mixed> The parsed locale parts.
	 */
	private function getParsedLocaleParts(): array
	{
		static $cache = [];
		$key = $this->getBaseLocaleIdentifier();
		if(!isset($cache[$key])) {
			if(class_exists(Locale::class)) {
				try {
					$cache[$key] = Locale::parseLocale($key) ?: [];
				} catch(\Throwable) {
					$cache[$key] = [];
				}
			} else {
				$cache[$key] = [];
			}
		}
		return $cache[$key];
	}

	/**
	 * Parses a locale identifier and returns its parts.
	 * @param      string $identifier The locale identifier.
	 * @return     array{language: ?string, script: ?string, territory: ?string, variant: ?string, options: array<string, string>, locale_str: ?string, option_str: ?string} The parts of the identifier
	 * @since      1.0.0
	 */
	public static function parseLocaleIdentifier($identifier)
	{
		// Parsing is a pure function of the identifier, so memoize the result for
		// the process lifetime -- parseLocaleIdentifier() is called several times
		// per locale switch (TranslationManager::setLocale/getLocale, per-key in
		// SimpleTranslator, etc.).
		static $cache = [];
		$cacheKey = (string) $identifier;
		if(isset($cache[$cacheKey])) {
			return $cache[$cacheKey];
		}

		$localeData = [
			'language' => null,
			'script' => null,
			'territory' => null,
			'variant' => null,
			'options' => [],
			'locale_str' => null,
			'option_str' => null,
		];

		if(preg_match(self::LOCALE_IDENTIFIER_REGEX, (string) $identifier, $match)) {
			$localeData['language'] = $match['language'];
			if(!empty($match['script'])) {
				$localeData['script'] = $match['script'];
			}
			if(!empty($match['territory'])) {
				$localeData['territory'] = $match['territory'];
			}
			if(!empty($match['variant'])) {
				$localeData['variant'] = $match['variant'];
			}

			if(!empty($match['options'])) {
				$localeData['option_str'] = '@' . $match['options'];

				// Historically Quiote locale option lists have appeared with either ',' or ';' as separators.
				// The legacy regex+explode only supported commas, which caused values like
				//   de_DE@timezone=Europe/Berlin;currency=EUR
				// to be interpreted as a single option timezone=Europe/Berlin;currency=EUR.
				// Accept both separators now for robustness and backward compatibility.
				$options = preg_split('/[;,]/', $match['options']);
				if (\is_array($options) === false) {
					$options = [];
				}
				foreach($options as $option) {
					$option = trim($option);
					if($option === '') { continue; }
					$optData = explode('=', $option, 2);
					$localeData['options'][$optData[0]] = (count($optData) === 2) ? $optData[1] : '';
				}
			}

			$localeData['locale_str'] = substr((string) $identifier, 0, strcspn((string) $identifier, '@'));
		} else {
			// Invalid identifiers are not cached (they throw, and are rare).
			throw new QuioteException('Invalid locale identifier (' . $identifier . ') specified');
		}

		return $cache[$cacheKey] = $localeData;
	}

	/**
	 * Returns all file names which need to be considered for the given
	 * identifier.
	 * @param      string|null|array{language: ?string, script: ?string, territory: ?string, variant: ?string, options: array<string, string>, locale_str: ?string, option_str: ?string} $localeIdentifier The locale identifier or the result of
	 *                   QuioteLocale::parseLocaleIdentifier. A null identifier is
	 *                   treated as the empty string, which parseLocaleIdentifier
	 *                   rejects as invalid.
	 * @return     array<int, string> The filenames.
	 * @since      1.0.0
	 */
	public static function getLookupPath($localeIdentifier)
	{
		if($localeIdentifier === null) {
			$localeIdentifier = '';
		}
		$localeInfo = is_array($localeIdentifier) ? $localeIdentifier : self::parseLocaleIdentifier($localeIdentifier);

		$language = (string) $localeInfo['language'];

		$paths = [];
		$path = $language;
		$paths[] = $path;

		if($localeInfo['territory']) {
			$path .= '_' . $localeInfo['territory'];
			$paths[] = $path;
		}

		if($localeInfo['variant']) {
			$path .= '_' . $localeInfo['variant'];
			$paths[] = $path;
		}

		if($localeInfo['script']) {
			$locPath = $language . '_' . $localeInfo['script'];
			$paths[] = $locPath;

			if($localeInfo['territory']) {
				$locPath .= '_' . $localeInfo['territory'];
				$paths[] = $locPath;
			}

			if($localeInfo['variant']) {
				$locPath .= '_' . $localeInfo['variant'];
				$paths[] = $locPath;
			}
		}

		return array_reverse($paths);
	}

	/**
	 * Returns this locale to its just-constructed state for reuse across requests.
	 *
	 * Drops the context, the loaded locale data, the identifier and the
	 * parameters, so a pooled worker re-initializes the locale from scratch
	 * rather than serving the previous request's language.
	 */
	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->data = [];
		$this->identifier = null;
		$this->parameters = [];
	}

	/**
	 * Record an ICU lookup that did not answer, so the fallback below it is traceable.
	 *
	 * These accessors each try ICU first and derive the value another way when it declines --
	 * an incomplete intl build, or a locale tag ICU will not parse, are the ordinary reasons.
	 * Debug level because falling back is the designed behaviour, not a fault; recorded because
	 * a locale silently resolving to its fallbacks is otherwise impossible to explain.
	 */
	private function logIcuProbeFailure(string $probe, \Throwable $e): void
	{
		\Quiote\Logging\Log::for($this)->debug(
			'[QuioteLocale] ICU ' . $probe . ' declined for "' . $this->getBaseLocaleIdentifier()
			. '", using the fallback: ' . $e->getMessage()
		);
	}
}
