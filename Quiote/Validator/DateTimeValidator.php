<?php
namespace Quiote\Validator;

use Quiote\Config\Config;
use Quiote\Exception\ConfigurationException;
use Quiote\Exception\ValidatorException;
use Quiote\I18n\DateTimeFacade;
use Quiote\Translation\DateFormatter;
use Quiote\Translation\QuioteLocale;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * DateTimeValidator verifies that a parameter is of a date and/or time 
 * format using native \DateTimeImmutable and IntlDateFormatter APIs. Inputs can
 * be supplied as a formatted string, UNIX timestamp, or a set of discrete date
 * components. All legacy Quiote calendar classes have been removed in favour of
 * first-party PHP primitives.
 * Arguments: 
 *   This can be:
 *    * a single argument which will then be parsed with the formats in the 
 *      'formats' parameter.
 *    * multiple arguments with the calendar constants 
 *      (DateDefinitions::MONTH, etc) as key and the argument field as 
 *      value.
 *    * multiple arguments and the 'arguments_format' parameter defined. This
 *      will use the string in 'arguments_format' as input string to sprintf and
 *      will use the arguments in the given order as argument to sprintf.
 * Parameters:
 *   'check'       check date if the specified day really exists
 *   'formats'     an array of arrays with these keys:
 *     'type'       The type of the string in 'format'.
 *     'format'     The input string dependent on the type. These types are 
 *                  allowed:
 *                    format:   The value is a date format string.
 *                    time:     The value is a time specifier (full,...) or null
 *                    date:     The value is a date specifier or null
 *                    datetime: The value is a date specifier or null
 *                    translation_domain: The value will be translated in the 
 *                              domain given in the 'translation_domain' key.
 *                    unix:     Always null/empty
 *                    unix_milliseconds: Always null/empty
 *     'locale'     The optional locale which will be used for this format.
 *     'translation_domain' Only applicable when the type is translation_domain
 *   'cast_to'     Optional post-processing. Strings: 'unix', 'string', 'datetime'.
 *                 Arrays: ['type' => 'format|time|date|datetime', 'format' => pattern or specifier].
 *                 Exported values always derive from native DateTimeImmutable instances.
 *   'arguments_format' A string which will be used as the format string for 
 *                 sprintf.
 *   'min'         Either an string or an array. When its a string the the 
 *                 its assumed to be in the format 'yyyy-MM-dd[ HH:mm:ss[.S]]'.
 *                 When its an array it will take the minimum value from a 
 *                 request field. These indizes apply:
 *     'format'      A custom format string which should be used when the field 
 *                   is an string.
 *     'field'       The name of the field to use as minimum value (could be a 
 *                   previous exported calendar object). Do NOT use unvalidated 
 *                   fields here. Lax parsing will be used.
 *                 This value is inclusive.
 *   'max'         The same as min except that the max is exclusive.
 * @since      1.0.0
 * @version    1.0.0
 */
class DateTimeValidator extends Validator
{
	/**
	 * Validates the input.
	 * @return     bool True if the input was a valid date.
	 * @since      1.0.0
	 */
	protected function validate()
	{
		if(!Config::get('core.use_translation')) {
			throw new ConfigurationException('The datetime validator can only be used with use_translation on');
		}

		$tm = $this->getContext()->getTranslationManager();
		$checkParam = $this->getParameter('check', true);
		$check = filter_var($checkParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
		if($check === null) {
			$check = (bool)$checkParam;
		}
		$locale = $this->hasParameter('locale') ? $tm->getLocale($this->getParameter('locale')) : $tm->getCurrentLocale();
		$timezoneId = $this->resolveTimezoneId($locale);

		$dateTime = null;
		if($this->hasMultipleArguments() && !$this->getParameter('arguments_format')) {
			$checkFailed = false;
			$dateTime = $this->assembleFromArgumentFields($timezoneId, $check, $checkFailed);
			if($dateTime === null) {
				$this->throwError($checkFailed ? 'check' : 'format');
				return false;
			}
		} else {
			$param = null;
			if($argFormat = $this->getParameter('arguments_format')) {
				$values = [];
				foreach($this->getArguments() as $field) {
					$values[] = $this->getData($field);
				}
				try {
					$param = vsprintf($argFormat, $values);
				} catch(Throwable) {
					$this->throwError('format');
					return false;
				}
			} else {
				$param = $this->getData($this->getArgument());
			}

			if(!is_scalar($param)) {
				$this->throwError();
				return false;
			}

			$dateTime = $this->parseInputValue((string)$param, (array)$this->getParameter('formats', []), $locale, $timezoneId);
			if($dateTime === null) {
				$this->throwError('format');
				return false;
			}
		}

		$subjectTs = $dateTime->getTimestamp();
		if($this->hasParameter('min')) {
			$min = $this->resolveBoundaryDate('min', $locale, $timezoneId);
			if($subjectTs < $min->getTimestamp()) {
				$this->throwError('min');
				return false;
			}
		}
		if($this->hasParameter('max')) {
			$max = $this->resolveBoundaryDate('max', $locale, $timezoneId);
			if($subjectTs >= $max->getTimestamp()) {
				$this->throwError('max');
				return false;
			}
		}

		$value = $dateTime;
		if($cast = $this->getParameter('cast_to')) {
			if(is_array($cast)) {
				$type = $cast['type'] ?? 'format';
				if($type === 'format') {
					$pattern = $cast['format'] ?? null;
					if($pattern === null) {
						throw new ConfigurationException('cast_to format requires a "format" value.');
					}
					$value = $this->formatPattern($dateTime, $pattern, $locale);
				} elseif(in_array($type, ['time', 'date', 'datetime'], true)) {
					$pattern = DateFormatter::resolveFormat($cast['format'] ?? null, $locale, $type);
					if($pattern === null) {
						throw new ConfigurationException('Unable to resolve cast_to pattern for type "' . $type . '".');
					}
					$value = $this->formatPattern($dateTime, $pattern, $locale);
				} else {
					throw new ConfigurationException('Unknown cast_to type "' . $type . '" supplied to DateTimeValidator.');
				}
			} else {
				$cast = strtolower((string)$cast);
				switch($cast) {
					case 'unix':
						$value = $subjectTs;
						break;
					case 'string':
						$value = $this->formatPattern($dateTime, 'yyyy-MM-dd HH:mm:ss', $locale);
						break;
					case 'datetime':
						$value = $dateTime;
						break;
					case 'calendar':
						throw new ConfigurationException('cast_to=calendar is no longer supported. Use cast_to=datetime instead.');
					default:
						$value = $dateTime;
				}
			}
		}

		if($this->hasParameter('export')) {
			$exportParam = $this->getParameter('export');
			if(is_array($exportParam)) {
				foreach($exportParam as $fieldKey => $target) {
					$component = $this->resolveExportComponent($dateTime, $fieldKey, $timezoneId);
					$this->export($component, $target);
				}
			} else {
				$this->export($value, is_string($exportParam) ? $exportParam : null);
			}
		}

		return true;
	}

	private function assembleFromArgumentFields(string $timezoneId, bool $check, bool &$checkFailed): ?DateTimeImmutable
	{
		$checkFailed = false;
		$fields = [
			'year' => null,
			'month' => null,
			'month_zero_based' => false,
			'day' => null,
			'hour' => null,
			'minute' => null,
			'second' => null,
			'millisecond' => null,
		];

		foreach($this->getArguments() as $rawKey => $argumentName) {
			$rawValue = $this->getData($argumentName);
			if($rawValue === null || $rawValue === '') {
				continue;
			}
			if(is_array($rawValue) || is_object($rawValue)) {
				continue;
			}
			$mapping = $this->normalizeFieldKey($rawKey);
			if($mapping === null) {
				throw new ValidatorException('Unknown argument name "' . $rawKey . '" for argument "' . $argumentName . '" supplied. Supported keys: YEAR, MONTH, DATE, HOUR_OF_DAY, MINUTE, SECOND.');
			}
			$value = (int)$rawValue;
			switch($mapping['field']) {
				case 'year':
					$fields['year'] = $value;
					break;
				case 'month':
					$fields['month'] = $value;
					if(!empty($mapping['zero_based'])) {
						$fields['month_zero_based'] = true;
					}
					break;
				case 'day':
					$fields['day'] = $value;
					break;
				case 'hour':
					$fields['hour'] = $value;
					break;
				case 'minute':
					$fields['minute'] = $value;
					break;
				case 'second':
					$fields['second'] = $value;
					break;
				case 'millisecond':
					$fields['millisecond'] = max(0, $value);
					break;
			}
		}

		if($fields['year'] === null) {
			return null;
		}

		$year = $fields['year'];
		$month = $fields['month'] ?? 1;
		if($fields['month_zero_based']) {
			$month += 1;
		}
		$day = $fields['day'] ?? 1;
		$hour = $fields['hour'] ?? 0;
		$minute = $fields['minute'] ?? 0;
		$second = $fields['second'] ?? 0;
		$millisecond = $fields['millisecond'];

		$tz = new DateTimeZone($timezoneId);
		$assembled = sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $assembled, $tz);
		if($date === false) {
			$checkFailed = true;
			return null;
		}
		if($millisecond !== null) {
			$date = $date->setTime((int)$date->format('H'), (int)$date->format('i'), (int)$date->format('s'), $millisecond * 1000);
		}
		if($check && !$this->matchesComponents($date, $year, $month, $day, $hour, $minute, $second, $millisecond)) {
			$checkFailed = true;
			return null;
		}

		return $date;
	}

	private function matchesComponents(DateTimeImmutable $dt, int $year, int $month, int $day, int $hour, int $minute, int $second, ?int $millisecond): bool
	{
		if((int)$dt->format('Y') !== $year) {
			return false;
		}
		if((int)$dt->format('n') !== $month) {
			return false;
		}
		if((int)$dt->format('j') !== $day) {
			return false;
		}
		if((int)$dt->format('G') !== $hour) {
			return false;
		}
		if((int)$dt->format('i') !== $minute) {
			return false;
		}
		if((int)$dt->format('s') !== $second) {
			return false;
		}
		if($millisecond !== null && (int)$dt->format('v') !== $millisecond) {
			return false;
		}
		return true;
	}

	private function resolveTimezoneId(QuioteLocale $locale): string
	{
		$tzId = $locale->getLocaleTimeZone();
		if($tzId) {
			return $tzId;
		}
		$tm = $this->getContext()->getTranslationManager();
		$defaultTz = $tm !== null ? $tm->getDefaultTimeZone() : null;
		if($defaultTz) {
			return $defaultTz->getName();
		}
		return date_default_timezone_get();
	}

	private function parseInputValue(string $value, array $formats, QuioteLocale $defaultLocale, string $timezoneId): ?DateTimeImmutable
	{
		$tm = $this->getContext()->getTranslationManager();
		$candidates = $formats;
		if(count($candidates) === 0) {
			$candidates = [['type' => 'format', 'format' => 'yyyy-MM-dd HH:mm:ss']];
		}
		foreach($candidates as $key => $item) {
			if(!is_array($item)) {
				$item = [is_int($key) ? 'format' : $key => $item];
			}
			$type = $item['type'] ?? 'format';
			$pattern = null;
			$itemLocale = empty($item['locale']) ? $defaultLocale : $tm->getLocale($item['locale']);
			try {
				switch($type) {
					case 'format':
						$pattern = (string)($item['format'] ?? '');
						if($pattern === '') {
							throw new ConfigurationException('Date format entry requires a "format" value.');
						}
						$dt = $this->parsePatternValue($value, $pattern, $itemLocale, $timezoneId);
						break;
					case 'time':
					case 'date':
					case 'datetime':
						$pattern = DateFormatter::resolveFormat($item['format'] ?? null, $itemLocale, $type);
						if($pattern === null) {
							throw new ConfigurationException('Unable to resolve ' . $type . ' format for locale ' . $itemLocale->getIdentifier());
						}
						$dt = $this->parsePatternValue($value, $pattern, $itemLocale, $timezoneId);
						break;
					case 'translation_domain':
						if(empty($item['translation_domain'])) {
							throw new ConfigurationException('translation_domain format requires a translation_domain value.');
						}
						$pattern = $tm->_($item['format'], $item['translation_domain'], $itemLocale);
						$dt = $this->parsePatternValue($value, $pattern, $itemLocale, $timezoneId);
						break;
					case 'unix':
						$dt = $this->isWholeNumber($value) ? (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone($timezoneId)) : null;
						break;
					case 'unix_milliseconds':
						$dt = is_numeric($value) ? $this->fromUnixMilliseconds($value, $timezoneId) : null;
						break;
					default:
						throw new ConfigurationException('Unknown datetime format type "' . $type . '" supplied to DateTimeValidator.');
				}
			} catch(Throwable) {
				$dt = null;
			}

			if($dt !== null) {
				return $dt;
			}
		}

		return null;
	}

	private function parsePatternValue(string $value, string $pattern, QuioteLocale $locale, string $timezoneId): ?DateTimeImmutable
	{
		$pattern = trim($pattern);
		if($pattern === '') {
			throw new ConfigurationException('Empty datetime format pattern supplied to DateTimeValidator.');
		}
		try {
			return DateTimeFacade::parse($value, $pattern, $timezoneId, $locale->getIdentifier());
		} catch(Throwable) {
			return null;
		}
	}

	private function formatPattern(DateTimeImmutable $date, string $pattern, QuioteLocale $locale): string
	{
		$pattern = trim($pattern);
		if($pattern === '') {
			throw new ConfigurationException('Empty datetime format pattern supplied to DateTimeValidator.');
		}
		return DateTimeFacade::format($date, $pattern, $locale->getIdentifier());
	}

	private function resolveBoundaryDate(string $parameterName, QuioteLocale $locale, string $timezoneId): DateTimeImmutable
	{
		$definition = $this->getParameter($parameterName);
		$tm = $this->getContext()->getTranslationManager();

		if($definition instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($definition)->setTimezone(new DateTimeZone($timezoneId));
		}

		if(is_array($definition)) {
			if(empty($definition['field'])) {
				throw new ConfigurationException('Boundary definition for ' . $parameterName . ' requires a "field" entry.');
			}
			$value = $this->validationParameters->getParameter($definition['field']);
			$format = $definition['format'] ?? null;
			$localeOverride = empty($definition['locale']) ? $locale : $tm->getLocale($definition['locale']);
			return $this->coerceBoundaryValue($value, $localeOverride, $timezoneId, $format);
		}

		return $this->coerceBoundaryValue($definition, $locale, $timezoneId, null);
	}

	private function coerceBoundaryValue($value, QuioteLocale $locale, string $timezoneId, ?string $format): DateTimeImmutable
	{
		if($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone($timezoneId));
		}
		if(is_array($value)) {
			throw new ValidatorException('Boundary values must not be arrays.');
		}
		if($format !== null) {
			$dt = $this->parsePatternValue((string)$value, $format, $locale, $timezoneId);
			if($dt === null) {
				throw new ValidatorException('Unable to parse boundary value "' . $value . '" using format "' . $format . '".');
			}
			return $dt;
		}
		if($this->isWholeNumber((string)$value)) {
			return (new DateTimeImmutable('@' . $value))->setTimezone(new DateTimeZone($timezoneId));
		}
		try {
			return new DateTimeImmutable((string)$value, new DateTimeZone($timezoneId));
		} catch(Throwable $e) {
			throw new ValidatorException('Unable to parse boundary value "' . $value . '".', 0, $e);
		}
	}

	private function isWholeNumber(string $value): bool
	{
		return preg_match('/^-?\d+$/', $value) === 1;
	}

	private function normalizeFieldKey($key): ?array
	{
		if(is_int($key) || (is_string($key) && ctype_digit($key))) {
			$intKey = (int)$key;
			if(isset(self::FIELD_KEY_NUMERIC[$intKey])) {
				return self::FIELD_KEY_NUMERIC[$intKey];
			}
		}
		if(is_string($key)) {
			$normalized = strtoupper(trim(ltrim($key, '\\')));
			if(isset(self::FIELD_KEY_ALIASES[$normalized])) {
				return self::FIELD_KEY_ALIASES[$normalized];
			}
		}
		return null;
	}

	private function fromUnixMilliseconds($value, string $timezoneId): ?DateTimeImmutable
	{
		$secondsFloat = ((float)$value) / 1000;
		$seconds = (int)floor($secondsFloat);
		$micro = (int)round(($secondsFloat - $seconds) * 1_000_000);
		if($micro >= 1_000_000) {
			$seconds += 1;
			$micro -= 1_000_000;
		}
		$dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, max(0, $micro)), new DateTimeZone('UTC'));
		if($dt === false) {
			return null;
		}
		return $dt->setTimezone(new DateTimeZone($timezoneId));
	}

	private function resolveExportComponent(DateTimeImmutable $date, $fieldKey, string $timezoneId)
	{
		$mapping = $this->normalizeFieldKey($fieldKey);
		if($mapping === null) {
			throw new ValidatorException('Unable to export unknown calendar field "' . $fieldKey . '".');
		}
		switch($mapping['field']) {
			case 'year':
				return (int)$date->format('Y');
			case 'month':
				$month = (int)$date->format('n');
				return !empty($mapping['zero_based']) ? $month - 1 : $month;
			case 'day':
				return (int)$date->format('j');
			case 'hour':
				return (int)$date->format('G');
			case 'minute':
				return (int)$date->format('i');
			case 'second':
				return (int)$date->format('s');
			case 'millisecond':
				return (int)$date->format('v');
			case 'milliseconds_in_day':
				return $this->computeMillisecondsInDay($date, $timezoneId);
			default:
				throw new ValidatorException('Export of field "' . $fieldKey . '" is no longer supported.');
		}
	}

	private function computeMillisecondsInDay(DateTimeImmutable $date, string $timezoneId): float
	{
		$tz = new DateTimeZone($timezoneId);
		$local = $date->setTimezone($tz);
		$midnight = $local->setTime(0, 0, 0, 0);
		$seconds = $local->getTimestamp() - $midnight->getTimestamp();
		return ($seconds * 1000.0) + (int)$local->format('v');
	}

	private const array FIELD_KEY_ALIASES = [
		'DATEDEFINITIONS::YEAR' => ['field' => 'year'],
		'YEAR' => ['field' => 'year'],
		'Y' => ['field' => 'year'],
		'DATEDEFINITIONS::MONTH' => ['field' => 'month', 'zero_based' => true],
		'MONTH' => ['field' => 'month', 'zero_based' => true],
		'DATEDEFINITIONS::DATE' => ['field' => 'day'],
		'DATE' => ['field' => 'day'],
		'DAY' => ['field' => 'day'],
		'DATEDEFINITIONS::DAY_OF_MONTH' => ['field' => 'day'],
		'DATEDEFINITIONS::HOUR_OF_DAY' => ['field' => 'hour'],
		'HOUR_OF_DAY' => ['field' => 'hour'],
		'HOUR' => ['field' => 'hour'],
		'DATEDEFINITIONS::MINUTE' => ['field' => 'minute'],
		'MINUTE' => ['field' => 'minute'],
		'DATEDEFINITIONS::SECOND' => ['field' => 'second'],
		'SECOND' => ['field' => 'second'],
		'DATEDEFINITIONS::MILLISECOND' => ['field' => 'millisecond'],
		'MILLISECOND' => ['field' => 'millisecond'],
		'DATEDEFINITIONS::MILLISECONDS_IN_DAY' => ['field' => 'milliseconds_in_day'],
		'MILLISECONDS_IN_DAY' => ['field' => 'milliseconds_in_day'],
	];

	private const array FIELD_KEY_NUMERIC = [
		1 => ['field' => 'year'],
		2 => ['field' => 'month', 'zero_based' => true],
		5 => ['field' => 'day'],
		11 => ['field' => 'hour'],
		12 => ['field' => 'minute'],
		13 => ['field' => 'second'],
		14 => ['field' => 'millisecond'],
	];
}

?>