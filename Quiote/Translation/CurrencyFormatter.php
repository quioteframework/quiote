<?php
namespace Quiote\Translation;
use Quiote\Translation\QuioteLocale;

use Quiote\Context;
use Quiote\Util\DecimalFormatter;
use Quiote\Util\Toolkit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The currency formatter will format numbers according to a given format and 
 * a given currency symbol
 * @since      1.0.0
 * @version    1.0.0
 */
class CurrencyFormatter extends DecimalFormatter implements ITranslator, ResetInterface
{
	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        array|string|null The custom format supplied by the user (if any).
	 */
	protected $customFormat = null;

	/**
	 * @var        string The iso code of the currency to be used for formatting.
	 */
	protected $currencyCode = '';

	/**
	 * @var        ?QuioteLocale The locale which should be used for formatting.
	 */
	protected $locale = null;

	/**
	 * @var        ?string The translation domain to translate the format (if any).
	 */
	protected $translationDomain = null;

	/**
	 * @see        ITranslator::getContext()
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Initialize this Translator.
	 * @param      Context $context The current application context.
	 * @param      array $parameters An associative array of initialization parameters
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		if(!empty($parameters['rounding_mode'])) {
			$this->setRoundingMode($this->getRoundingModeFromString($parameters['rounding_mode']));
		} else {
			$this->setRoundingMode(DecimalFormatter::ROUND_NONE);
		}
		if(isset($parameters['translation_domain'])) {
			$this->translationDomain = $parameters['translation_domain'];
		}
		if(isset($parameters['format'])) {
			$this->customFormat = $parameters['format'];
			if(is_array($this->customFormat)) {
				// it's an array, so it contains the translations already, DOMAIN MUST NOT BE SET
				$this->translationDomain = null;
			} elseif($this->translationDomain === null) {
				// if the translation domain is not set and the format is not an array of per-locale strings then we don't have to delay parsing
				$this->setFormat($this->customFormat);
			}
		}
		if(isset($parameters['currency_code'])) {
			$this->currencyCode = $parameters['currency_code'];
		}
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
		if($locale) {
			$fn = clone $this;
			$fn->localeChanged($locale);
		} else {
			$fn = $this;
			$locale = $this->locale;
		}
		
		if($this->customFormat && $this->translationDomain) {
			if($fn === $this) {
				$fn = clone $this;
			}
			
			$td = $this->translationDomain . ($domain ? '.' . $domain : '');
			$format = $this->getContext()->getTranslationManager()->_($this->customFormat, $td, $locale);
			
			$fn->setFormat($format);
		}
		
		$code = $this->getCurrencyCode();
		$fraction = $this->getContext()->getTranslationManager()->getCurrencyFraction($code);
		$fn->setFractionDigits($fraction['digits']);
		
		if($fraction['rounding'] > 0) {
			$roundingUnit = 10 ** -$fraction['digits'] * $fraction['rounding'];
			$message = round($message / $roundingUnit) * $roundingUnit;
		}
		
		return $fn->formatCurrency($message, $fn->getCurrencySymbol());
	}

	/**
	 * This method gets called by the translation manager when the default locale
	 * has been changed.
	 * @param      QuioteLocale $newLocale The new default locale.
	 * @since      1.0.0
	 */
	public function localeChanged($newLocale)
	{
		$this->locale = $newLocale;

		$format = null;
		if(class_exists(\NumberFormatter::class)) {
			try {
				$nf = new \NumberFormatter($this->locale->getIdentifier(), \NumberFormatter::CURRENCY);
				$this->groupingSeparator = $nf->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);
				$this->decimalSeparator = $nf->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL);
				$pattern = $nf->getPattern();
				if($pattern !== '') {
					$format = $pattern;
				}
			} catch(\Throwable) {
			}
		}

		if($format === null) {
			$format = '¤#,##0.00';
		}
		
		if(is_array($this->customFormat)) {
			$format = Toolkit::getValueByKeyList($this->customFormat, QuioteLocale::getLookupPath($this->locale->getIdentifier()), $format);
		} elseif($this->customFormat) {
			$format = $this->customFormat;
		}
		
		$this->setFormat($format);
	}

	/**
	 * Returns the iso code of the currency which should be used when formatting.
	 * @return     string The currency iso code.
	 * @since      1.0.0
	 */
	public function getCurrencyCode()
	{
		$code = $this->currencyCode;
		if(!$code && $this->locale) {
			$code = $this->locale->getLocaleCurrency();
		}

		return $code;
	}

	/**
	 * Returns the currency symbol which should be used when formatting.
	 * @return     string The currency symbol
	 * @since      1.0.0
	 */
	public function getCurrencySymbol()
	{
		$code = $this->getCurrencyCode();
		if(!$this->locale) {
			return $code;
		}

		$symbol = $code;
		$name = $code;

		if(class_exists(\NumberFormatter::class)) {
			try {
				$nf = new \NumberFormatter($this->locale->getIdentifier(), \NumberFormatter::CURRENCY);
				$nf->setTextAttribute(\NumberFormatter::CURRENCY_CODE, $code);
				$sym = $nf->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);
				if($sym !== '') {
					$symbol = $sym;
				}
				$display = self::resolveCurrencyDisplayName($this->locale->getIdentifier(), $code);
				if($display !== null) {
					$name = $display;
				}
			} catch(\Throwable) {
			}
		}

		return match ($this->currencyType) {
			DecimalFormatter::CURRENCY_SYMBOL => $symbol,
			DecimalFormatter::CURRENCY_CODE => $code,
			DecimalFormatter::CURRENCY_NAME => $name,
			default => null,
		};
	}

	private static function resolveCurrencyDisplayName(string $localeId, string $code): ?string
	{
		if(!class_exists(\ResourceBundle::class)) {
			return null;
		}

		try {
			$bundle = \ResourceBundle::create($localeId, 'ICUDATA-curr');
			if($bundle instanceof \ResourceBundle) {
				$currencies = $bundle['Currencies'] ?? null;
				if($currencies instanceof \ResourceBundle && isset($currencies[$code])) {
					$entry = $currencies[$code];
					if(is_array($entry) && isset($entry[1]) && is_string($entry[1])) {
						return $entry[1];
					}
				}
			}
		} catch(\Throwable) {
		}

		return null;
	}

	/**
	 * Sets the amount of fractional digits to be shown.
	 * @param      int $count The amount of digits.
	 * @since      1.0.0
	 */
	public function setFractionDigits($count)
	{
		$this->maxShowedFractionals = $this->minShowedFractionals = $count;
	}
}

?>