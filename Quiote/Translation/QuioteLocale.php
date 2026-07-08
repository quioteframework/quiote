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
			} catch(\Throwable) {
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
			} catch(\Throwable) {
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
			} catch(\Throwable) {
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
		} catch(\Throwable) {
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
			} catch(\Throwable) {
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
		// the only important thing here is the forward assertion which is needed
		// so it doesn't match the first character of the territory
		$baseLocaleRx = '(?P<language>[^_@]{2,3})(?:_(?P<script>[^_@](?=@|_|$)|[^_@]{4,}))?(?:_(?P<territory>[^_@]{2,3}))?(?:_(?P<variant>[^@]+))?';
		$optionsRx = '@(?P<options>.*)';

		$localeRx = '#^(' . $baseLocaleRx . ')(' . $optionsRx . ')?$#';

		$localeData = [
			'language' => null,
			'script' => null,
			'territory' => null,
			'variant' => null,
			'options' => [],
			'locale_str' => null,
			'option_str' => null,
		];

		if(preg_match($localeRx, (string) $identifier, $match)) {
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
			throw new QuioteException('Invalid locale identifier (' . $identifier . ') specified');
		}

		return $localeData;
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

	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->data = [];
		$this->identifier = null;
		$this->parameters = [];
	}
}
