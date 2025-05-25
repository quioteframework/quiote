<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\Translation;

use Agavi\AgaviContext;
use Agavi\DateTime\AgaviCalendar;
use Agavi\Exception\AgaviException;
use Agavi\Util\AgaviParameterHolder;

/**
 * The locale saves all kind of info about a locale
 *
 * @package    agavi
 * @subpackage translation
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviLocale extends AgaviParameterHolder
{
	/**
	 * @var        AgaviContext An AgaviContext instance.
	 */
	protected $context = null;

	/**
	 * @var        array The data.
	 */
	protected $data = [];

	/**
	 * @var        string The identifier of this locale.
	 */
	protected $identifier = null;

	/**
	 * Returns the locale option string containing the timezone option set 
	 * to the timezone of this calendar.
	 * 
	 * @param      AgaviCalendar|DateTime|int The item to determine the timezone
	 *                                        from
	 * @param      string The prefix which will be applied to the timezone option
	 *                    string. Use ';' here if you intend to use several 
	 *                    locale options and append the result of this method
	 *                    to your locale string.
	 *
	 * @return     string Returns an empty string (NOT containing the $prefix)
	 *                    if $item is invalid or no timezone could be determined
	 * 
	 * @author     Dominik del Bondio <dominik.del.bondio@bitextender.com>
	 * @since      1.0.0
	 */
	public static function getTimeZoneOptionString($item, $prefix = '@')
	{
		$tzId = '';
		if($item instanceof AgaviCalendar) {
			$tzId = $item->getTimeZone()->getResolvedId();
		} elseif($item instanceof \DateTime) {
			$tzId = $item->getTimezone()->getName();
			if(preg_match('/^[+-][0-9]+/', $tzId)) {
				$tzId = 'GMT' . $tzId;
			}
		} elseif(is_int($item)) {
			$tzId = 'UTC';
		}
		
		if($tzId) {
			return $prefix . 'timezone=' . $tzId;
		} else {
			return '';
		}
	}

	/**
	 * Initialize this Locale.
	 *
	 * @param      AgaviContext The current application context.
	 * @param      array        An associative array of initialization parameters.
	 * @param      string       The identifier of the locale
	 * @param      array        The locale data.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function initialize(AgaviContext $context, array $parameters = [], $identifier = null, array $data = [])
	{
		$this->context = $context;
		$this->parameters = $parameters;
		
		$this->identifier = $identifier;
		$this->data = $data;
	}

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext The current AgaviContext instance.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Returns the identifier of this locale
	 *
	 * @return     string The identifier.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getIdentifier()
	{
		return $this->identifier;
	}

	////////////////////////////// Locale data //////////////////////////////////

	public function getLocaleLanguage()
	{
		return $this->data['locale']['language'] ?? null;
	}

	public function getLocaleTerritory()
	{
		return $this->data['locale']['territory'] ?? null;
	}

	public function getLocaleScript()
	{
		return $this->data['locale']['script'] ?? null;
	}

	public function getLocaleVariant()
	{
		return $this->data['locale']['variant'] ?? null;
	}

	public function getLocaleCurrency()
	{
		return $this->data['locale']['currency'] ?? null;
	}

	public function getLocaleCalendar()
	{
		return $this->data['locale']['calendar'] ?? $this->getDefaultCalendar();
	}

	public function getLocaleTimeZone()
	{
		return $this->data['locale']['timezone'] ?? null;
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

	public function getCurrencySpacingBeforeCurrencyCurrencyMatch()
	{
		return $this->data['numbers']['currencySpacing']['beforeCurrency']['currencyMatch'] ?? null;
	}

	public function getCurrencySpacingBeforeCurrencySurroundingMatch()
	{
		return $this->data['numbers']['currencySpacing']['beforeCurrency']['surroundingMatch'] ?? null;
	}

	public function getCurrencySpacingBeforeCurrencyInsertBetween()
	{
		return $this->data['numbers']['currencySpacing']['beforeCurrency']['insertBetween'] ?? null;
	}

	public function getCurrencySpacingAfterCurrencyCurrencyMatch()
	{
		return $this->data['numbers']['currencySpacing']['afterCurrency']['currencyMatch'] ?? null;
	}

	public function getCurrencySpacingAfterCurrencySurroundingMatch()
	{
		return $this->data['numbers']['currencySpacing']['afterCurrency']['surroundingMatch'] ?? null;
	}

	public function getCurrencySpacingAfterCurrencyInsertBetween()
	{
		return $this->data['numbers']['currencySpacing']['afterCurrency']['insertBetween'] ?? null;
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
	 *
	 * @param      string The locale identifier.
	 *
	 * @return     array The parts of the identifier
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
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

				$options = explode(',', $match['options']);
				foreach($options as $option) {
					$optData = explode('=', $option, 2);
					$localeData['options'][$optData[0]] = $optData[1];
				}
			}

			$localeData['locale_str'] = substr((string) $identifier, 0, strcspn((string) $identifier, '@'));
		} else {
			throw new AgaviException('Invalid locale identifier (' . $identifier . ') specified');
		}

		return $localeData;
	}

	/**
	 * Returns all file names which need to be considered for the given 
	 * identifier. 
	 *
	 * @param      mixed The locale identifier or the result of 
	 *                   AgaviLocale::parseLocaleIdentifier
	 *
	 * @return     array The filenames.
	 *
	 * @author     Dominik del Bondio <ddb@bitxtender.com>
	 * @since      0.11.0
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
}

?>