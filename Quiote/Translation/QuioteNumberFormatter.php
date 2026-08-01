<?php
namespace Quiote\Translation;
use Quiote\Translation\QuioteLocale;

use Quiote\Context;
use Quiote\Util\DecimalFormatter;
use Quiote\Util\Toolkit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The number formatter will format numbers according to a given format
 * @since      1.0.0
 * @version    1.0.0
 */
class QuioteNumberFormatter extends DecimalFormatter implements ITranslator, ResetInterface
{
	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        ?QuioteLocale The locale which should be used for formatting.
	 */
	protected $locale = null;

	/**
	 * @var        array<int|string, mixed>|string|null The custom format supplied by the user (if any).
	 */
	protected $customFormat = null;

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
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters
	 * @return     void
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;
		if(!empty($parameters['rounding_mode'])) {
			if(!is_string($parameters['rounding_mode'])) {
				throw new \Quiote\Exception\QuioteException('QuioteNumberFormatter::initialize() expects the "rounding_mode" parameter to be a string, ' . get_debug_type($parameters['rounding_mode']) . ' given.');
			}
			$this->setRoundingMode($this->getRoundingModeFromString($parameters['rounding_mode']));
		}
		if(isset($parameters['translation_domain'])) {
			if(!is_string($parameters['translation_domain'])) {
				throw new \Quiote\Exception\QuioteException('QuioteNumberFormatter::initialize() expects the "translation_domain" parameter to be a string, ' . get_debug_type($parameters['translation_domain']) . ' given.');
			}
			$this->translationDomain = $parameters['translation_domain'];
		}
		if(isset($parameters['format'])) {
			if(!is_array($parameters['format']) && !is_string($parameters['format'])) {
				throw new \Quiote\Exception\QuioteException('QuioteNumberFormatter::initialize() expects the "format" parameter to be an array or a string, ' . get_debug_type($parameters['format']) . ' given.');
			}
			$this->customFormat = $parameters['format'];
			if(is_array($this->customFormat)) {
				// it's an array, so it contains the translations already, DOMAIN MUST NOT BE SET
				$this->translationDomain = null;
			} elseif($this->translationDomain === null) {
				// if the translation domain is not set and the format is not an array of per-locale strings then we don't have to delay parsing
				$this->setFormat($this->customFormat);
			}
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
		if(!is_int($message) && !is_float($message) && !is_string($message)) {
			throw new \Quiote\Exception\QuioteException('QuioteNumberFormatter::translate() expects $message to be an int, float or string, ' . get_debug_type($message) . ' given.');
		}

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
			
			$context = $this->getContext();
			$translationManager = $context !== null ? $context->getTranslationManager() : null;
			if($translationManager === null) {
				throw new \Quiote\Exception\QuioteException('Cannot translate number format: translations are disabled or the translation manager is unavailable.');
			}

			$td = $this->translationDomain . ($domain ? '.' . $domain : '');
			$format = $translationManager->_($this->customFormat, $td, $locale);

			$fn->setFormat($format);
		}
		
		return $fn->formatNumber($message);
	}

	/**
	 * This method gets called by the translation manager when the default locale
	 * has been changed.
	 * @param      QuioteLocale $newLocale The new default locale.
	 * @return     void
	 * @since      1.0.0
	 */
	public function localeChanged($newLocale)
	{
		$this->locale = $newLocale;

		$format = null;
		$localeIdentifier = $this->locale->getIdentifier();
		if($localeIdentifier !== null && class_exists(\NumberFormatter::class)) {
			$symbols = self::decimalSymbolsFor($localeIdentifier);
			if($symbols !== null) {
				$this->groupingSeparator = $symbols['grouping'];
				$this->decimalSeparator = $symbols['decimal'];
				if($symbols['pattern'] !== '') {
					$format = $symbols['pattern'];
				}
			}
		}

		if($format === null) {
			$format = '#,##0.###';
		}
		
		if(is_array($this->customFormat)) {
			$format = Toolkit::getValueByKeyList($this->customFormat, QuioteLocale::getLookupPath($this->locale->getIdentifier()), $format);
		} elseif($this->customFormat) {
			$format = $this->customFormat;
		}

		if(!is_string($format)) {
			throw new \Quiote\Exception\QuioteException('QuioteNumberFormatter::localeChanged() resolved a non-string number format for locale "' . ($this->locale->getIdentifier() ?? '') . '".');
		}

		$this->setFormat($format);
	}

	/**
	 * Reset per-request locale state for worker compatibility. context,
	 * customFormat and translationDomain are configured once from initialize()
	 * parameters and never restored afterward -- clearing them here would
	 * silently fall back to the hardcoded default number format for every
	 * subsequent request. Only locale (and the derived format fields cleared
	 * by parent::reset(), which localeChanged() always recomputes) are
	 * per-request.
	 * @since      1.0.0
	 */
	#[\Override]
    public function reset() : void
	{
		$this->locale = null;

		parent::reset();
	}

	/**
	 * Locale-scoped decimal grouping/decimal separators and base pattern, read
	 * from a NumberFormatter once per locale and memoized for the process
	 * lifetime. ICU locale data is immutable at runtime and keyed by the full
	 * locale identifier, so this static cache is worker-safe (it intentionally
	 * survives reset()) and removes the per-call NumberFormatter construction
	 * that ran on every locale-scoped _n().
	 * @return array{grouping: string, decimal: string, pattern: string}|null
	 */
	private static function decimalSymbolsFor(string $localeIdentifier): ?array
	{
		static $cache = [];
		if(array_key_exists($localeIdentifier, $cache)) {
			return $cache[$localeIdentifier];
		}
		try {
			$nf = new \NumberFormatter($localeIdentifier, \NumberFormatter::DECIMAL);
			return $cache[$localeIdentifier] = [
				'grouping' => $nf->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL),
				'decimal' => $nf->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL),
				'pattern' => $nf->getPattern(),
			];
		} catch(\Throwable) {
			return $cache[$localeIdentifier] = null;
		}
	}
}

?>