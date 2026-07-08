<?php
namespace Quiote\Translation;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Util\ParameterHolder;
use Locale;
use NumberFormatter;
use Symfony\Contracts\Service\ResetInterface;
use function array_slice;
use function count;
use function is_array;
use function is_string;
use function strlen;

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
		return $this->getDefaultCalendar();
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

	///////////////////////////// locale names //////////////////////////////////

	/**
	 * @return     void
	 */
	protected function generateCountryList()
	{
		if(!isset($this->data['displayNames']) || !is_array($this->data['displayNames']) || !isset($this->data['displayNames']['territories'])) {
			return;
		}

		$terrs = is_array($this->data['displayNames']['territories']) ? $this->data['displayNames']['territories'] : [];

		// we assume that the territories are the first items in the list
		$i = 0;
		foreach($terrs as $key => $val) {
			// territories consist of 3 letter keys while countries only consist of 2 letter keys
			if(is_string($key) && strlen($key) == 2) {
				break;
			}
			++$i;
		}

		$this->data['displayNames']['countries'] = array_slice($terrs, $i, count($terrs) - $i, true);
	}

	/**
	 * @return     ?array<string, string> The list of countries, keyed by country code.
	 */
	public function getCountries()
	{
		if(!isset($this->data['displayNames']) || !is_array($this->data['displayNames']) || !isset($this->data['displayNames']['countries'])) {
			$this->generateCountryList();
		}
		if (isset($this->data['displayNames']) && is_array($this->data['displayNames']) && isset($this->data['displayNames']['countries']) && is_array($this->data['displayNames']['countries'])) {
			return $this->data['displayNames']['countries'];
		}

		return null;
	}

	/**
	 * @param      string $id The country code.
	 * @return     ?string The display name of the country.
	 */
	public function getCountry(string $id)
	{
		if(!isset($this->data['displayNames']) ||
		   !is_array($this->data['displayNames']) || 
		   !isset($this->data['displayNames']['countries']) || 
		   !is_array($this->data['displayNames']['countries']) ||
		   !isset($this->data['displayNames']['countries'][$id]) ) {
			$this->generateCountryList();
		}

		if (isset($this->data['displayNames']) &&
			is_array($this->data['displayNames']) &&
			isset($this->data['displayNames']['countries']) &&
			is_array($this->data['displayNames']['countries']) &&
		    isset($this->data['displayNames']['countries'][$id])) {
			return is_string($this->data['displayNames']['countries'][$id]) ? $this->data['displayNames']['countries'][$id] : null;
		}
		return null;
	}

	/**
	 * @return     ?array<string, string> The list of languages, keyed by language code.
	 */
	public function getLanguages()
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['languages']) && 
						is_array($this->data['displayNames']['languages'])) ? $this->data['displayNames']['languages'] : null;
		return $returnValue;
	}

	/**
	 * @param      string $id The language code.
	 * @return     ?string The display name of the language.
	 */
	public function getLanguage(string $id)
	{
		if (!isset($this->data['displayNames']) || 
		    !is_array($this->data['displayNames']) || 
			!isset($this->data['displayNames']['languages']) || 
			!is_array($this->data['displayNames']['languages']) || 
			!isset($this->data['displayNames']['languages'][$id]) ) {
			return null;
		}
		return $this->data['displayNames']['languages'][$id] ?? null;
		
	}

	/**
	 * @return     ?array<string, string> The list of scripts, keyed by script code.
	 */
	public function getScripts()
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['scripts']) && 
						is_array($this->data['displayNames']['scripts'])) ? $this->data['displayNames']['scripts'] : null;
		return $returnValue;
	}

	/**
	 * @param      string $id The script code.
	 * @return     ?string The display name of the script.
	 */
	public function getScript(string $id)
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['scripts']) && 
						is_array($this->data['displayNames']['scripts']) && 
						isset($this->data['displayNames']['scripts'][$id])) ? $this->data['displayNames']['scripts'][$id] : null;
		return $returnValue;
	}

	/**
	 * @return     ?array<string, string> The list of territories, keyed by territory code.
	 */
	public function getTerritories()
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['territories']) && 
						is_array($this->data['displayNames']['territories'])) ? $this->data['displayNames']['territories'] : null;	
		return $returnValue;
	}

	/**
	 * @param      string $id The territory code.
	 * @return     ?string The display name of the territory.
	 */
	public function getTerritory(string $id)
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['territories']) && 
						is_array($this->data['displayNames']['territories']) && 
						isset($this->data['displayNames']['territories'][$id])) ? $this->data['displayNames']['territories'][$id] : null;
		return $returnValue;
	}

	/**
	 * @return     ?array<string, string> The list of variants, keyed by variant code.
	 */
	public function getVariants()
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['variants']) && 
						is_array($this->data['displayNames']['variants'])) ? $this->data['displayNames']['variants'] : null;
		return $returnValue;
	}

	/**
	 * @param      string $id The variant code.
	 * @return     ?string The display name of the variant.
	 */
	public function getVariant(string $id)
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['variants']) && 
						is_array($this->data['displayNames']['variants']) && 
						isset($this->data['displayNames']['variants'][$id])) ? $this->data['displayNames']['variants'][$id] : null;
		return $returnValue;
	}

	/**
	 * @return     ?array<string, string> The list of measurement system names, keyed by id.
	 */
	public function getMeasurementSystemNames()
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['measurementSystemNames']) && 
						is_array($this->data['displayNames']['measurementSystemNames'])) ? $this->data['displayNames']['measurementSystemNames'] : null;
		return $returnValue;
	}

	/**
	 * @param      string $id The measurement system id.
	 * @return     ?string The display name of the measurement system.
	 */
	public function getMeasurementSystemName(string $id)
	{
		$returnValue = (isset($this->data['displayNames']) && 
		                is_array($this->data['displayNames']) && 
						isset($this->data['displayNames']['measurementSystemNames']) && 
						is_array($this->data['displayNames']['measurementSystemNames']) && 
						isset($this->data['displayNames']['measurementSystemNames'][$id]) && 
						is_string($this->data['displayNames']['measurementSystemNames'][$id])) ? $this->data['displayNames']['measurementSystemNames'][$id] : null;
		return $returnValue;
	}

	//////////////////////////////// layout /////////////////////////////////////

	/**
	 * @return     ?string The line orientation.
	 */
	public function getLineOrientation()
	{
		$returnValue = (isset($this->data['layout']) && 
		                is_array($this->data['layout']) && 
						isset($this->data['layout']['orientation']) && 
						is_array($this->data['layout']['orientation']) && 
						isset($this->data['layout']['orientation']['lines'])) ? $this->data['layout']['orientation']['lines'] : null;
		return $returnValue;
	}

	/**
	 * @return     ?string The character orientation.
	 */
	public function getCharacterOrientation()
	{
		$returnValue = (isset($this->data['layout']) && 
		                is_array($this->data['layout']) && 
						isset($this->data['layout']['orientation']) && 
						is_array($this->data['layout']['orientation']) && 
						isset($this->data['layout']['orientation']['characters'])) ? $this->data['layout']['orientation']['characters'] : null;
		return $returnValue;
	}

	//////////////////////////////// delimiters /////////////////////////////////

	/**
	 * @return     ?string The quotation start delimiter.
	 */
	public function getQuotationStart()
	{
		$returnValue = (isset($this->data['delimiters']) && 
		                is_array($this->data['delimiters']) && 
						isset($this->data['delimiters']['quotationStart']) && 
						is_string($this->data['delimiters']['quotationStart'])) ? $this->data['delimiters']['quotationStart'] : null;
		return $returnValue;
	}

	/**
	 * @return     ?string The quotation end delimiter.
	 */
	public function getQuotationEnd()
	{
		$returnValue = (isset($this->data['delimiters']) && 
		                is_array($this->data['delimiters']) && 
						isset($this->data['delimiters']['quotationEnd']) && 
						is_string($this->data['delimiters']['quotationEnd'])) ? $this->data['delimiters']['quotationEnd'] : null;
		return $returnValue;
	}

	/**
	 * @return     ?string The alternate quotation start delimiter.
	 */
	public function getAlternateQuotationStart()
	{
		$returnValue = (isset($this->data['delimiters']) && 
		                is_array($this->data['delimiters']) && 
						isset($this->data['delimiters']['altQuotationStart']) && 
						is_string($this->data['delimiters']['altQuotationStart'])) ? $this->data['delimiters']['altQuotationStart'] : null;
		return $returnValue;
	}

	/**
	 * @return     ?string The alternate quotation end delimiter.
	 */
	public function getAlternateQuotationEnd()
	{
		$returnValue = (isset($this->data['delimiters']) && 
		                is_array($this->data['delimiters']) && 
						isset($this->data['delimiters']['altQuotationEnd']) && 
						is_string($this->data['delimiters']['altQuotationEnd'])) ? $this->data['delimiters']['altQuotationEnd'] : null;
		return $returnValue;
	}

	//////////////////////////////// calendars //////////////////////////////////

	/**
	 * @return     ?string The default calendar identifier.
	 */
	public function getDefaultCalendar()
	{
		$returnValue = (isset($this->data['calendars']) && 
		                is_array($this->data['calendars']) && 
						isset($this->data['calendars']['default']) && 
						is_string($this->data['calendars']['default'])) ? $this->data['calendars']['default'] : null;
		return $returnValue;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarMonthsWide($calendar)
	{
		$returnValue = (isset($this->data['calendars'][$calendar]['months']['format']['wide']) && 
		                is_array($this->data['calendars'][$calendar]['months']['format']['wide'])) ? $this->data['calendars'][$calendar]['months']['format']['wide'] : null;
		return $returnValue;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $month
	 * @return ?string
	 */
	public function getCalendarMonthWide($calendar, $month)
	{
		$returnValue = (isset($this->data['calendars'][$calendar]['months']['format']['wide'][$month]) && 
		                is_string($this->data['calendars'][$calendar]['months']['format']['wide'][$month])) ? $this->data['calendars'][$calendar]['months']['format']['wide'][$month] : null;
		return $returnValue;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarMonthsAbbreviated($calendar)
	{
		$returnValue = (isset($this->data['calendars'][$calendar]['months']['format']['abbreviated']) && 
		                is_array($this->data['calendars'][$calendar]['months']['format']['abbreviated'])) ? $this->data['calendars'][$calendar]['months']['format']['abbreviated'] : null;
		return $returnValue;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $month
	 * @return ?string
	 */
	public function getCalendarMonthAbbreviated($calendar, $month)
	{
		return $this->data['calendars'][$calendar]['months']['format']['abbreviated'][$month] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarMonthsNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['months']['stand-alone']['narrow'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $month
	 * @return ?string
	 */
	public function getCalendarMonthNarrow($calendar, $month)
	{
		return $this->data['calendars'][$calendar]['months']['stand-alone']['narrow'][$month] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarDaysWide($calendar)
	{
		return $this->data['calendars'][$calendar]['days']['format']['wide'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $day
	 * @return ?string
	 */
	public function getCalendarDayWide($calendar, $day)
	{
		return $this->data['calendars'][$calendar]['days']['format']['wide'][$day] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarDaysAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['days']['format']['abbreviated'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $day
	 * @return ?string
	 */
	public function getCalendarDayAbbreviated($calendar, $day)
	{
		return $this->data['calendars'][$calendar]['days']['format']['abbreviated'][$day] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarDaysNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['days']['stand-alone']['narrow'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $day
	 * @return ?string
	 */
	public function getCalendarDayNarrow($calendar, $day)
	{
		return $this->data['calendars'][$calendar]['days']['stand-alone']['narrow'][$day] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarQuartersWide($calendar)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['wide'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $quarter
	 * @return ?string
	 */
	public function getCalendarQuarterWide($calendar, $quarter)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['wide'][$quarter] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarQuartersAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['abbreviated'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $quarter
	 * @return ?string
	 */
	public function getCalendarQuarterAbbreviated($calendar, $quarter)
	{
		return $this->data['calendars'][$calendar]['quarters']['format']['abbreviated'][$quarter] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarQuartersNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['quarters']['stand-alone']['narrow'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $quarter
	 * @return ?string
	 */
	public function getCalendarQuarterNarrow($calendar, $quarter)
	{
		return $this->data['calendars'][$calendar]['quarters']['stand-alone']['narrow'][$quarter] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return ?string
	 */
	public function getCalendarAm($calendar)
	{
		return $this->data['calendars'][$calendar]['am'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return ?string
	 */
	public function getCalendarPm($calendar)
	{
		return $this->data['calendars'][$calendar]['pm'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarErasWide($calendar)
	{
		return $this->data['calendars'][$calendar]['eras']['wide'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $era
	 * @return ?string
	 */
	public function getCalendarEraWide($calendar, $era)
	{
		return $this->data['calendars'][$calendar]['eras']['wide'][$era] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarErasAbbreviated($calendar)
	{
		return $this->data['calendars'][$calendar]['eras']['abbreviated'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $era
	 * @return ?string
	 */
	public function getCalendarEraAbbreviated($calendar, $era)
	{
		return $this->data['calendars'][$calendar]['eras']['abbreviated'][$era] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarErasNarrow($calendar)
	{
		return $this->data['calendars'][$calendar]['eras']['narrow'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $era
	 * @return ?string
	 */
	public function getCalendarEraNarrow($calendar, $era)
	{
		return $this->data['calendars'][$calendar]['eras']['narrow'][$era] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return ?string
	 */
	public function getCalendarDateFormatDefaultName($calendar)
	{
		return $this->data['calendars'][$calendar]['dateFormats']['default'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarDateFormats($calendar)
	{
		return $this->data['calendars'][$calendar]['dateFormats'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return array<string, mixed>|string|null
	 */
	public function getCalendarDateFormat($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateFormats'][$id] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return ?string
	 */
	public function getCalendarDateFormatPattern($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateFormats'][$id]['pattern'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return ?string
	 */
	public function getCalendarDateFormatDisplayName($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateFormats'][$id]['displayName'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return ?string
	 */
	public function getCalendarTimeFormatDefaultName($calendar)
	{
		return $this->data['calendars'][$calendar]['timeFormats']['default'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarTimeFormats($calendar)
	{
		return $this->data['calendars'][$calendar]['timeFormats'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return array<string, mixed>|string|null
	 */
	public function getCalendarTimeFormat($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['timeFormats'][$id] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return ?string
	 */
	public function getCalendarTimeFormatPattern($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['timeFormats'][$id]['pattern'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return ?string
	 */
	public function getCalendarTimeFormatDisplayName($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['timeFormats'][$id]['displayName'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return ?string
	 */
	public function getCalendarDateTimeFormatDefaultName($calendar)
	{
		return $this->data['calendars'][$calendar]['dateTimeFormats']['default'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarDateTimeFormats($calendar)
	{
		return $this->data['calendars'][$calendar]['dateTimeFormats']['formats'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return array<string, mixed>|string|null
	 */
	public function getCalendarDateTimeFormat($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['dateTimeFormats']['formats'][$id] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarFields($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return array<string, mixed>|string|null
	 */
	public function getCalendarField($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'][$id] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return ?string
	 */
	public function getCalendarFieldDisplayName($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'][$id]['displayName'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @return array<int|string, mixed>|null
	 */
	public function getCalendarFieldRelatives($calendar, $id)
	{
		return $this->data['calendars'][$calendar]['fields'][$id]['relatives'] ?? null;
	}

	/**
	 * @param mixed $calendar
	 * @param mixed $id
	 * @param mixed $rId
	 * @return ?string
	 */
	public function getCalendarFieldRelative($calendar, $id, $rId)
	{
		return $this->data['calendars'][$calendar]['fields'][$id]['relatives'][$rId] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getTimeZoneHourFormat()
	{
		return $this->data['timeZoneNames']['hourFormat'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getTimeZoneHoursFormat()
	{
		return $this->data['timeZoneNames']['hoursFormat'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getTimeZoneGmtFormat()
	{
		return $this->data['timeZoneNames']['gmtFormat'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getTimeZoneRegionFormat()
	{
		return $this->data['timeZoneNames']['regionFormat'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getTimeZoneFallbackFormat()
	{
		return $this->data['timeZoneNames']['fallbackFormat'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getTimeZoneAbbreviationFormat()
	{
		return $this->data['timeZoneNames']['abbreviationFormat'] ?? null;
	}

	/**
	 * @param mixed $tz
	 * @return ?string
	 */
	public function getTimeZoneLongGenericName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['long']['generic'] ?? null;
	}

	/**
	 * @param mixed $tz
	 * @return ?string
	 */
	public function getTimeZoneLongStandardName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['long']['standard'] ?? null;
	}

	/**
	 * @param mixed $tz
	 * @return ?string
	 */
	public function getTimeZoneLongDaylightName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['long']['daylight'] ?? null;
	}

	/**
	 * @param mixed $tz
	 * @return ?string
	 */
	public function getTimeZoneShortGenericName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['short']['generic'] ?? null;
	}

	/**
	 * @param mixed $tz
	 * @return ?string
	 */
	public function getTimeZoneShortStandardName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['short']['standard'] ?? null;
	}

	/**
	 * @param mixed $tz
	 * @return ?string
	 */
	public function getTimeZoneShortDaylightName($tz)
	{
		return $this->data['timeZoneNames']['zones'][$tz]['short']['daylight'] ?? null;
	}

	/**
	 * @return array<int|string, mixed>
	 */
	public function getTimeZoneNames()
	{
		return $this->data['timeZoneNames']['zones'] ?? [];
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolDecimal()
	{
		return $this->data['numbers']['symbols']['decimal'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolGroup()
	{
		return $this->data['numbers']['symbols']['group'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolList()
	{
		return $this->data['numbers']['symbols']['list'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolPercentSign()
	{
		return $this->data['numbers']['symbols']['percentSign'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolZeroDigit()
	{
		return $this->data['numbers']['symbols']['nativeZeroDigit'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolPatternDigit()
	{
		return $this->data['numbers']['symbols']['patternDigit'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolPlusSign()
	{
		return $this->data['numbers']['symbols']['plusSign'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolMinusSign()
	{
		return $this->data['numbers']['symbols']['minusSign'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolExponential()
	{
		return $this->data['numbers']['symbols']['exponential'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolPerMille()
	{
		return $this->data['numbers']['symbols']['perMille'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolInfinity()
	{
		return $this->data['numbers']['symbols']['infinity'] ?? null;
	}

	/**
	 * @return ?string
	 */
	public function getNumberSymbolNaN()
	{
		return $this->data['numbers']['symbols']['nan'] ?? null;
	}

	/**
	 * @param mixed $dfId
	 * @return array<string, mixed>|string|null
	 */
	public function getDecimalFormat($dfId)
	{
		return $this->data['numbers']['decimalFormats'][$dfId] ?? null;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	public function getDecimalFormats()
	{
		return $this->data['numbers']['decimalFormats'] ?? null;
	}

	/**
	 * @param mixed $sfId
	 * @return array<string, mixed>|string|null
	 */
	public function getScientificFormat($sfId)
	{
		return $this->data['numbers']['scientificFormats'][$sfId] ?? null;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	public function getScientificFormats()
	{
		return $this->data['numbers']['scientificFormats'] ?? null;
	}

	/**
	 * @param mixed $pfId
	 * @return array<string, mixed>|string|null
	 */
	public function getPercentFormat($pfId)
	{
		return $this->data['numbers']['percentFormats'][$pfId] ?? null;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	public function getPercentFormats()
	{
		return $this->data['numbers']['percentFormats'] ?? null;
	}

	/**
	 * @param mixed $cfId
	 * @return array<string, mixed>|string|null
	 */
	public function getCurrencyFormat($cfId)
	{
		return $this->data['numbers']['currencyFormats'][$cfId] ?? null;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	public function getCurrencyFormats()
	{
		return $this->data['numbers']['currencyFormats'] ?? null;
	}

	/**
	 * @return array<int|string, mixed>|null
	 */
	public function getCurrencies()
	{
		return $this->data['numbers']['currencies'] ?? null;
	}

	/**
	 * @param mixed $cId
	 * @return array<string, mixed>|string|null
	 */
	public function getCurrency($cId)
	{
		return $this->data['numbers']['currencies'][$cId] ?? null;
	}

	/**
	 * @param mixed $cId
	 * @return ?string
	 */
	public function getCurrencyDisplayName($cId)
	{
		return $this->data['numbers']['currencies'][$cId]['displayName'] ?? null;
	}

	/**
	 * @param mixed $cId
	 * @return ?string
	 */
	public function getCurrencySymbol($cId)
	{
		return $this->data['numbers']['currencies'][$cId]['symbol'] ?? null;
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
	 * @param      mixed $localeIdentifier The locale identifier or the result of
	 *                   QuioteLocale::parseLocaleIdentifier
	 * @return     array<int, string> The filenames.
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
