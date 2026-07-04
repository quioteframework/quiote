<?php
namespace Quiote\Translation;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Util\ParameterHolder;
use Locale;
use NumberFormatter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The locale saves all kind of info about a locale
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
	 * @var        array The data.
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
	 * @param      array $parameters An associative array of initialization parameters.
	 * @param      string $identifier The identifier of the locale
	 * @param      array $data The locale data.
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
	 * @return     Context The current Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Returns the identifier of this locale
	 * @return     string The identifier.
	 * @since      1.0.0
	 */
	public function getIdentifier()
	{
		return $this->identifier;
	}

	////////////////////////////// Locale data //////////////////////////////////

	public function getLocaleLanguage()
	{
		if(isset($this->data['locale']['language'])) {
			return $this->data['locale']['language'];
		}

		if(class_exists(Locale::class)) {
			try {
				return Locale::getPrimaryLanguage($this->getBaseLocaleIdentifier()) ?: null;
			} catch(\Throwable) {
			}
		}

		return null;
	}

	public function getLocaleTerritory()
	{
		if(isset($this->data['locale']['territory'])) {
			return $this->data['locale']['territory'];
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

	public function getLocaleScript()
	{
		if(isset($this->data['locale']['script'])) {
			return $this->data['locale']['script'];
		}

		if(class_exists(Locale::class)) {
			try {
				$script = Locale::getScript($this->getBaseLocaleIdentifier());
				if($script === '') {
					$parts = $this->getParsedLocaleParts();
					$script = $parts['script'] ?? '';
				}
				return $script !== '' ? $script : null;
			} catch(\Throwable) {
			}
		}

		return null;
	}

	public function getLocaleVariant()
	{
		if(isset($this->data['locale']['variant'])) {
			return $this->data['locale']['variant'];
		}

		if(class_exists(QuioteLocale::class)) {
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
		}

		return null;
	}

	public function getLocaleCurrency()
	{
		if(isset($this->data['locale']['currency'])) {
			return $this->data['locale']['currency'];
		}
		if(isset($this->data['locale']['currencyOverride'])) {
			return $this->data['locale']['currencyOverride'];
		}
		if(isset($this->parameters['currency'])) {
			return $this->parameters['currency'];
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

	public function getLocaleCalendar()
	{
		return $this->data['locale']['calendar'] ?? $this->parameters['calendar'] ?? $this->getDefaultCalendar();
	}

	public function getLocaleTimeZone()
	{
		return $this->data['locale']['timezone'] ?? $this->parameters['timezone'] ?? null;
	}

	private function getBaseLocaleIdentifier(): string
	{
		$identifier = (string) $this->identifier;
		$pos = strpos($identifier, '@');
		return $pos === false ? $identifier : substr($identifier, 0, $pos);
	}

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

	///////////////////////////// locale names //////////////////////////////////

	protected function generateCountryList()
	{
		if(!isset($this->data['displayNames']['territories'])) {
			return;
		}

		$terrs = $this->data['displayNames']['territories'];

		// we assume that the territories are the first items in the list
		$i = 0;
		foreach($terrs as $key => $val) {
			// territories consist of 3 letter keys while countries only consist of 2 letter keys
			if(strlen((string) $key) == 2) {
				break;
			}
			++$i;
		}

		$this->data['displayNames']['countries'] = array_slice($terrs, $i, count($terrs) - $i, true);
	}

	public function getCountries()
	{
		if(!isset($this->data['displayNames']['countries'])) {
			$this->generateCountryList();
		}

		return $this->data['displayNames']['countries'] ?? null;
	}

	public function getCountry($id)
	{
		if(!isset($this->data['displayNames']['countries'])) {
			$this->generateCountryList();
		}

		return $this->data['displayNames']['countries'][$id] ?? null;
	}

	public function getLanguages()
	{
		return $this->data['displayNames']['languages'] ?? null;
	}

	public function getLanguage($id)
	{
		return $this->data['displayNames']['languages'][$id] ?? null;
	}

	public function getScripts()
	{
		return $this->data['displayNames']['scripts'] ?? null;
	}

	public function getScript($id)
	{
		return $this->data['displayNames']['scripts'][$id] ?? null;
	}

	public function getTerritories()
	{
		return $this->data['displayNames']['territories'] ?? null;
	}

	public function getTerritory($id)
	{
		return $this->data['displayNames']['territories'][$id] ?? null;
	}

	public function getVariants()
	{
		return $this->data['displayNames']['variants'] ?? null;
	}

	public function getVariant($id)
	{
		return $this->data['displayNames']['variants'][$id] ?? null;
	}

	public function getMeasurementSystemNames()
	{
		return $this->data['displayNames']['measurementSystemNames'] ?? null;
	}

	public function getMeasurementSystemName($id)
	{
		return $this->data['displayNames']['measurementSystemNames'][$id] ?? null;
	}

	//////////////////////////////// layout /////////////////////////////////////

	public function getLineOrientation()
	{
		return $this->data['layout']['orientation']['lines'] ?? null;
	}

	public function getCharacterOrientation()
	{
		return $this->data['layout']['orientation']['characters'] ?? null;
	}

	//////////////////////////////// delimiters /////////////////////////////////

	public function getQuotationStart()
	{
		return $this->data['delimiters']['quotationStart'] ?? null;
	}

	public function getQuotationEnd()
	{
		return $this->data['delimiters']['quotationEnd'] ?? null;
	}

	public function getAlternateQuotationStart()
	{
		return $this->data['delimiters']['altQuotationStart'] ?? null;
	}

	public function getAlternateQuotationEnd()
	{
		return $this->data['delimiters']['altQuotationEnd'] ?? null;
	}

	//////////////////////////////// calendars //////////////////////////////////

	public function getDefaultCalendar()
	{
		return $this->data['calendars']['default'] ?? null;
	}

	public function getCalendarMonthsWide($calendar)
	{
		return $this->data['calendars'][$calendar]['months']['format']['wide'] ?? null;
	}

	public function getCalendarMonthWide($calendar, $month)
	{
		return $this->data['calendars'][$calendar]['months']['format']['wide'][$month] ?? null;
	}

	public function getCalendarMonthsAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['months']['format']['abbreviated'] ?? null;
	}

	public function getCalendarMonthAbbreviated($calendar, $month)
	{
		return $this->data['calendars'][$calendar]['months']['format']['abbreviated'][$month] ?? null;
	}

	public function getCalendarMonthsNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['months']['stand-alone']['narrow'] ?? null;
	}

	public function getCalendarMonthNarrow($calendar, $month)
	{
		return $this->data['calendars'][$calendar]['months']['stand-alone']['narrow'][$month] ?? null;
	}

	public function getCalendarDaysWide($calendar)
	{
		return $this->data['calendars'][$calendar]['days']['format']['wide'] ?? null;
	}

	public function getCalendarDayWide($calendar, $day)
	{
		return $this->data['calendars'][$calendar]['days']['format']['wide'][$day] ?? null;
	}

	public function getCalendarDaysAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['days']['format']['abbreviated'] ?? null;
	}

	public function getCalendarDayAbbreviated($calendar, $day)
	{
		return $this->data['calendars'][$calendar]['days']['format']['abbreviated'][$day] ?? null;
	}

	public function getCalendarDaysNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['days']['stand-alone']['narrow'] ?? null;
	}

	public function getCalendarDayNarrow($calendar, $day)
	{
		return $this->data['calendars'][$calendar]['days']['stand-alone']['narrow'][$day] ?? null;
	}

	public function getCalendarQuartersWide($calendar)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['wide'] ?? null;
	}

	public function getCalendarQuarterWide($calendar, $quarter)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['wide'][$quarter] ?? null;
	}

	public function getCalendarQuartersAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['abbreviated'] ?? null;
	}

	public function getCalendarQuarterAbbreviated($calendar, $quarter)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['abbreviated'][$quarter] ?? null;
	}

	public function getCalendarQuartersNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['quarters']['stand-alone']['narrow'] ?? null;
	}

	public function getCalendarQuarterNarrow($calendar, $quarter)
	{
		return $this->data['calendars'][$calendar]['quarters']['stand-alone']['narrow'][$quarter] ?? null;
	}

	public function getCalendarAm($calendar)
	{
		return $this->data['calendars'][$calendar]['am'] ?? null;
	}

	public function getCalendarPm($calendar)
	{
		return $this->data['calendars'][$calendar]['pm'] ?? null;
	}

	public function getCalendarErasWide($calendar)
	{
		return $this->data['calendars'][$calendar]['eras']['wide'] ?? null;
	}

	public function getCalendarEraWide($calendar, $era)
	{
		return $this->data['calendars'][$calendar]['eras']['wide'][$era] ?? null;
	}

	public function getCalendarErasAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['eras']['abbreviated'] ?? null;
	}

	public function getCalendarEraAbbreviated($calendar, $era)
	{
		return $this->data['calendars'][$calendar]['eras']['abbreviated'][$era] ?? null;
	}

	public function getCalendarErasNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['eras']['narrow'] ?? null;
	}

	public function getCalendarEraNarrow($calendar, $era)
	{
		return $this->data['calendars'][$calendar]['eras']['narrow'][$era] ?? null;
	}

	public function getCalendarDateFormatDefaultName($calendar)
	{
		return $this->data['calendars'][$calendar]['dateFormats']['default'] ?? null;
	}

	public function getCalendarDateFormats($calendar)
	{
		return $this->data['calendars'][$calendar]['dateFormats'] ?? null;
	}

	public function getCalendarDateFormat($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateFormats'][$id] ?? null;
	}

	public function getCalendarDateFormatPattern($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateFormats'][$id]['pattern'] ?? null;
	}

	public function getCalendarDateFormatDisplayName($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateFormats'][$id]['displayName'] ?? null;
	}

	public function getCalendarTimeFormatDefaultName($calendar)
	{
		return $this->data['calendars'][$calendar]['timeFormats']['default'] ?? null;
	}

	public function getCalendarTimeFormats($calendar)
	{
		return $this->data['calendars'][$calendar]['timeFormats'] ?? null;
	}

	public function getCalendarTimeFormat($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['timeFormats'][$id] ?? null;
	}

	public function getCalendarTimeFormatPattern($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['timeFormats'][$id]['pattern'] ?? null;
	}

	public function getCalendarTimeFormatDisplayName($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['timeFormats'][$id]['displayName'] ?? null;
	}

	public function getCalendarDateTimeFormatDefaultName($calendar)
	{
		return $this->data['calendars'][$calendar]['dateTimeFormats']['default'] ?? null;
	}

	public function getCalendarDateTimeFormats($calendar)
	{
		return $this->data['calendars'][$calendar]['dateTimeFormats']['formats'] ?? null;
	}

	public function getCalendarDateTimeFormat($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateTimeFormats']['formats'][$id] ?? null;
	}

	public function getCalendarFields($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'] ?? null;
	}

	public function getCalendarField($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'][$id] ?? null;
	}

	public function getCalendarFieldDisplayName($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'][$id]['displayName'] ?? null;
	}

	public function getCalendarFieldRelatives($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'][$id]['relatives'] ?? null;
	}

	public function getCalendarFieldRelative($calendar, $id, $rId)
	{
		return $this->data['calendars'][$calendar]['fields'][$id]['relatives'][$rId] ?? null;
	}

	public function getTimeZoneHourFormat()
	{
		return $this->data['timeZoneNames']['hourFormat'] ?? null;
	}

	public function getTimeZoneHoursFormat()
	{
		return $this->data['timeZoneNames']['hoursFormat'] ?? null;
	}

	public function getTimeZoneGmtFormat()
	{
		return $this->data['timeZoneNames']['gmtFormat'] ?? null;
	}

	public function getTimeZoneRegionFormat()
	{
		return $this->data['timeZoneNames']['regionFormat'] ?? null;
	}

	public function getTimeZoneFallbackFormat()
	{
		return $this->data['timeZoneNames']['fallbackFormat'] ?? null;
	}

	public function getTimeZoneAbbreviationFormat()
	{
		return $this->data['timeZoneNames']['abbreviationFormat'] ?? null;
	}

	public function getTimeZoneLongGenericName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['long']['generic'] ?? null;
	}

	public function getTimeZoneLongStandardName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['long']['standard'] ?? null;
	}

	public function getTimeZoneLongDaylightName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['long']['daylight'] ?? null;
	}

	public function getTimeZoneShortGenericName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['short']['generic'] ?? null;
	}

	public function getTimeZoneShortStandardName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['short']['standard'] ?? null;
	}

	public function getTimeZoneShortDaylightName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['short']['daylight'] ?? null;
	}

	public function getTimeZoneNames()
	{
		return $this->data['timeZoneNames']['zones'] ?? [];
	}

	public function getNumberSymbolDecimal()
	{
		return $this->data['numbers']['symbols']['decimal'] ?? null;
	}

	public function getNumberSymbolGroup()
	{
		return $this->data['numbers']['symbols']['group'] ?? null;
	}

	public function getNumberSymbolList()
	{
		return $this->data['numbers']['symbols']['list'] ?? null;
	}

	public function getNumberSymbolPercentSign()
	{
		return $this->data['numbers']['symbols']['percentSign'] ?? null;
	}

	public function getNumberSymbolZeroDigit()
	{
		return $this->data['numbers']['symbols']['nativeZeroDigit'] ?? null;
	}

	public function getNumberSymbolPatternDigit()
	{
		return $this->data['numbers']['symbols']['patternDigit'] ?? null;
	}

	public function getNumberSymbolPlusSign()
	{
		return $this->data['numbers']['symbols']['plusSign'] ?? null;
	}

	public function getNumberSymbolMinusSign()
	{
		return $this->data['numbers']['symbols']['minusSign'] ?? null;
	}

	public function getNumberSymbolExponential()
	{
		return $this->data['numbers']['symbols']['exponential'] ?? null;
	}

	public function getNumberSymbolPerMille()
	{
		return $this->data['numbers']['symbols']['perMille'] ?? null;
	}

	public function getNumberSymbolInfinity()
	{
		return $this->data['numbers']['symbols']['infinity'] ?? null;
	}

	public function getNumberSymbolNaN()
	{
		return $this->data['numbers']['symbols']['nan'] ?? null;
	}

	public function getDecimalFormat($dfId)
	{
		return $this->data['numbers']['decimalFormats'][$dfId] ?? null;
	}

	public function getDecimalFormats()
	{
		return $this->data['numbers']['decimalFormats'] ?? null;
	}

	public function getScientificFormat($sfId)
	{
		return $this->data['numbers']['scientificFormats'][$sfId] ?? null;
	}

	public function getScientificFormats()
	{
		return $this->data['numbers']['scientificFormats'] ?? null;
	}

	public function getPercentFormat($pfId)
	{
		return $this->data['numbers']['percentFormats'][$pfId] ?? null;
	}

	public function getPercentFormats()
	{
		return $this->data['numbers']['percentFormats'] ?? null;
	}

	public function getCurrencyFormat($cfId)
	{
		return $this->data['numbers']['currencyFormats'][$cfId] ?? null;
	}

	public function getCurrencyFormats()
	{
		return $this->data['numbers']['currencyFormats'] ?? null;
	}

	public function getCurrencies()
	{
		return $this->data['numbers']['currencies'] ?? null;
	}

	public function getCurrency($cId)
	{
		return $this->data['numbers']['currencies'][$cId] ?? null;
	}

	public function getCurrencyDisplayName($cId)
	{
		return $this->data['numbers']['currencies'][$cId]['displayName'] ?? null;
	}

	public function getCurrencySymbol($cId)
	{
		return $this->data['numbers']['currencies'][$cId]['symbol'] ?? null;
	}

	/**
	 * Parses a locale identifier and returns its parts.
	 * @param      string $identifier The locale identifier.
	 * @return     array The parts of the identifier
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
				foreach($options as $option) {
					$option = trim($option);
					if($option === '') { continue; }
					$optData = explode('=', $option, 2);
					if(count($optData) === 2) {
						$localeData['options'][$optData[0]] = $optData[1];
					} else {
						// Flag option without value; treat as empty string
						$localeData['options'][$optData[0]] = '';
					}
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
	 * @param      mixed $localeIdentifier The locale identifier or the result of 
	 *                   QuioteLocale::parseLocaleIdentifier
	 * @return     array The filenames.
	 * @since      1.0.0
	 */
	public static function getLookupPath($localeIdentifier)
	{
		if(is_array($localeIdentifier)) {
			$localeInfo = $localeIdentifier;
		} else {
			$localeInfo = self::parseLocaleIdentifier($localeIdentifier);
		}

		$scriptPart = null;
		$path = $localeInfo['language'];
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
			$locPath = $localeInfo['language'] . '_' . $localeInfo['script'];
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
