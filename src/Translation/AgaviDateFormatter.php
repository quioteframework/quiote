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
use Agavi\Exception\AgaviException;
use Agavi\I18n\DateTimeFacade;
use Agavi\Util\AgaviToolkit;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The date formatter will dates numbers according to a given format
 *
 * @package    agavi
 * @subpackage translation
 *
 * @author     Dominik del Bondio <ddb@bitxtender.com>
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
class AgaviDateFormatter implements AgaviITranslator, ResetInterface
{
    private const DEFAULT_CALENDAR = 'gregorian';

	/** @var AgaviContext */
	protected $context;

	/** @var AgaviLocale|null */
	protected $locale = null;

	/** @var string */
	protected $type = 'datetime';

	/** @var mixed */
	protected $customFormat = null;

	/** @var string|null */
	protected $translationDomain = null;

	/** @var string|null */
	protected $resolvedPattern = null;

	public function initialize(AgaviContext $context, array $parameters = [])
	{
		$this->context = $context;
		$this->type = 'datetime';

		if(isset($parameters['translation_domain'])) {
			$this->translationDomain = $parameters['translation_domain'];
		}

		if(isset($parameters['type']) && in_array($parameters['type'], ['date', 'time', 'datetime'], true)) {
			$this->type = $parameters['type'];
		}

		if(array_key_exists('format', $parameters)) {
			$this->customFormat = $parameters['format'];
			if(is_array($this->customFormat)) {
				// Pre-translated map, don't allow translation domain override.
				$this->translationDomain = null;
			}
		}
	}

	public function getContext()
	{
		return $this->context;
	}

	public function translate($message, $domain, ?AgaviLocale $locale = null)
	{
		if(!$this->locale && !$locale) {
			throw new AgaviException('AgaviDateFormatter has not been prepared with a locale yet.');
		}

		$formatter = $this;
		if($locale) {
			$formatter = clone $this;
			$formatter->localeChanged($locale);
		} else {
			$locale = $this->locale;
		}

		$pattern = $formatter->resolvedPattern;
		if($formatter->customFormat && $formatter->translationDomain) {
			$td = $formatter->translationDomain . ($domain ? '.' . $domain : '');
			$format = $formatter->context->getTranslationManager()->_($formatter->customFormat, $td, $locale);
			$pattern = $formatter->resolvePattern($locale, $format);
		}

		if($pattern === null) {
			throw new AgaviException('No date format pattern resolved for AgaviDateFormatter.');
		}

		$dt = $formatter->coerceToDateTime($message, $locale);
		return $formatter->formatWithPattern($dt, $pattern, $locale);
	}

	public function localeChanged($newLocale)
	{
		$this->locale = $newLocale;

		$format = null;
		if(is_array($this->customFormat)) {
			$format = AgaviToolkit::getValueByKeyList($this->customFormat, AgaviLocale::getLookupPath($newLocale->getIdentifier()));
		} elseif($this->customFormat && !$this->translationDomain) {
			$format = $this->customFormat;
		}

		$this->resolvedPattern = $this->resolvePattern($newLocale, $format);
	}

	public static function resolveFormat($format, $locale, $type = 'datetime')
	{
		if(self::isDateSpecifier($format)) {
			return self::resolveSpecifierPattern($locale, $format, $type);
		}

		return $format;
	}

	protected static function isDateSpecifier($format)
	{
		static $specifiers = ['full', 'long', 'medium', 'short'];
		return in_array($format, $specifiers, true);
	}

	public function reset() : void
	{
		$this->locale = null;
		$this->type = 'datetime';
		$this->customFormat = null;
		$this->translationDomain = null;
		$this->resolvedPattern = null;
	}

	protected function resolvePattern(AgaviLocale $locale, $format): ?string
	{
		if($format === null || self::isDateSpecifier($format)) {
			return self::resolveSpecifierPattern($locale, $format, $this->type);
		}

		return (string) $format;
	}

	protected static function resolveSpecifierPattern(AgaviLocale $locale, $spec, $type): ?string
	{
		$type = $type ?: 'datetime';
		$specifier = $spec ?? 'medium';

		$dateStyle = $type !== 'time' ? self::mapStyleToIntlConstant($specifier) : IntlDateFormatter::NONE;
		$timeStyle = $type !== 'date' ? self::mapStyleToIntlConstant($specifier) : IntlDateFormatter::NONE;

		$calendarId = $locale->getLocaleCalendar() ?? self::DEFAULT_CALENDAR;
		$localeId = self::localeWithCalendar($locale->getIdentifier(), $calendarId);
		$timezone = $locale->getLocaleTimeZone() ?? date_default_timezone_get();
		$calendarConst = (strcasecmp($calendarId, self::DEFAULT_CALENDAR) === 0) ? IntlDateFormatter::GREGORIAN : IntlDateFormatter::TRADITIONAL;

		try {
			$formatter = new IntlDateFormatter($localeId, $dateStyle, $timeStyle, $timezone, $calendarConst);
			$pattern = $formatter->getPattern();
			return $pattern !== false ? $pattern : null;
		} catch(\Throwable) {
			return null;
		}
	}

	private static function mapStyleToIntlConstant(?string $specifier): int
	{
		return match(strtolower((string) $specifier)) {
			'full' => IntlDateFormatter::FULL,
			'long' => IntlDateFormatter::LONG,
			'short' => IntlDateFormatter::SHORT,
			default => IntlDateFormatter::MEDIUM,
		};
	}

	private static function localeWithCalendar(string $localeId, ?string $calendar): string
	{
		if(!$calendar || strcasecmp($calendar, self::DEFAULT_CALENDAR) === 0) {
			return $localeId;
		}

		$normalized = $localeId . '@calendar=' . $calendar;
		if(class_exists(\Locale::class)) {
			try {
				$canon = \Locale::canonicalize($normalized);
				if(is_string($canon) && $canon !== '') {
					return $canon;
				}
			} catch(\Throwable) {
			}
		}

		return $normalized;
	}

	protected function coerceToDateTime($value, AgaviLocale $locale): DateTimeImmutable
	{
		$timezone = $this->resolveTimezone($locale);

		if($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value)->setTimezone($timezone);
		}

		if(is_int($value) || (is_string($value) && ctype_digit($value))) {
			return (new DateTimeImmutable('@' . $value))->setTimezone($timezone);
		}

		if(is_string($value)) {
			try {
				return new DateTimeImmutable($value, $timezone);
			} catch(\Throwable $e) {
				throw new AgaviException('Unable to parse date string "' . $value . '".', 0, $e);
			}
		}

		throw new AgaviException('Unsupported datetime value supplied to AgaviDateFormatter.');
	}

	protected function resolveTimezone(AgaviLocale $locale): DateTimeZone
	{
		$tzId = $locale->getLocaleTimeZone();
		if(!$tzId) {
			$defaultTz = $this->context->getTranslationManager()->getDefaultTimeZone();
			$tzId = $defaultTz ? $defaultTz->getName() : date_default_timezone_get();
		}

		return new DateTimeZone($tzId);
	}

	protected function formatWithPattern(DateTimeInterface $dt, string $pattern, AgaviLocale $locale): string
	{
		$timezone = $this->resolveTimezone($locale);
		$immutable = DateTimeImmutable::createFromInterface($dt)->setTimezone($timezone);

		if(class_exists(IntlDateFormatter::class)) {
			$formatter = new IntlDateFormatter(
				$locale->getIdentifier(),
				IntlDateFormatter::NONE,
				IntlDateFormatter::NONE,
				$timezone->getName(),
				IntlDateFormatter::GREGORIAN,
				$pattern
			);
			$formatter->setTimeZone($timezone);
			$result = $formatter->format($immutable);
			if($result !== false) {
				return $result;
			}
		}

		// Fallback to limited subset formatting via DateTimeFacade.
		try {
			return DateTimeFacade::format($immutable, $pattern, $locale->getIdentifier());
		} catch(\Throwable $e) {
			throw new AgaviException('Failed to format date using pattern "' . $pattern . '".', 0, $e);
		}
	}
}