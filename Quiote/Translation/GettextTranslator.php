<?php
namespace Quiote\Translation;
use Quiote\Translation\QuioteLocale;

use Quiote\Context;
use Quiote\Exception\QuioteException;
use Quiote\Translation\Gettext\GettextMoReader;
use Quiote\Util\Toolkit;
use Symfony\Contracts\Service\ResetInterface;

/**
 * GettextTranslator defines the translator interface for gettext.
 * @since      1.0.0
 * @version    1.0.0
 */
class GettextTranslator extends BasicTranslator implements ResetInterface
{
	/**
	 * @var        ?string A pattern for the path to the domain files.
	 */
	protected $domainPathPattern = null;

	/**
	 * @var        array<string, string> The paths to the locale files indexed by domains
	 */
	protected $domainPaths = [];

	/**
	 * @var        array<string, array{headers: array<string, string>, msgs: array<string, string>}> The data for each domain
	 */
	protected $domainData = [];
	
	/**
	 * @var        ?QuioteLocale The locale identifier of the current locale
	 */
	protected $locale = null;

	/**
	 * @var        ?\Closure The plural form determination function
	 */
	protected $pluralFormFunc = null;

	/**
	 * @var        bool Whether or not to write a file with all used translations
	 *                  that can be parsed using xgettext.
	 */
	protected $storeTranslationCalls = false;

	/**
	 * @var        ?string The folder to write translation call files to.
	 */
	protected $translationCallStoreDir = null;

	/**
	 * Initialize this Translator.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters
	 * @return     void
	 * @since      1.0.0
	 */
	#[\Override]
    public function initialize(Context $context, array $parameters = [])
	{
		parent::initialize($context);

		if(isset($parameters['text_domains']) && is_array($parameters['text_domains'])) {
			foreach($parameters['text_domains'] as $domain => $path) {
				$this->domainPaths[$domain] = $path;
			}
		}

		if(isset($parameters['text_domain_pattern'])) {
			$this->domainPathPattern = $parameters['text_domain_pattern'];
		}
		
		if(isset($parameters['store_calls'])) {
			$this->storeTranslationCalls = true;
			$this->translationCallStoreDir = $parameters['store_calls'];
			Toolkit::mkdir($parameters['store_calls'], 0777, true);
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
			$oldDomainData = $this->domainData;
			$oldLocale = $this->locale;
			$this->localeChanged($locale);
		}

		// load domain data from file
		if(!isset($this->domainData[$domain])) {
			$this->loadDomainData($domain);
		}

		if(is_array($message)) {
			$singularMsg = $message[0];
			$pluralMsg = $message[1];
			$count = $message[2];
			if($this->pluralFormFunc) {
				$funcName = $this->pluralFormFunc;
				$msgId = (int) $funcName($count);
			} else {
				$msgId = ($count == 1) ? 0 : 1;
			}

			$msgKey = $singularMsg . chr(0) . $pluralMsg;

			if(isset($this->domainData[$domain]['msgs'][$msgKey])) {
				$pluralMsgs = explode(chr(0), $this->domainData[$domain]['msgs'][$msgKey]);
				$data = $pluralMsgs[$msgId];
			} else {
				$data = ($msgId == 0) ? $singularMsg : $pluralMsg;
			}
		} else {
			$data = $this->domainData[$domain]['msgs'][$message] ?? $message;
		}

		// in "devel" mode, write a gettext() or ngettext() call to a file for xgettext parsing
		if($this->storeTranslationCalls) {
			file_put_contents(
				$this->translationCallStoreDir . DIRECTORY_SEPARATOR . $domain . '.php', 
				"" . (is_array($message) ? 
					('ngettext(' . var_export($message[0], true) . ', ' . var_export($message[1], true) . ', ' . var_export($message[2], true) . ')') :
					('gettext(' . var_export($message, true) . ')')
				) . ";\n",
			FILE_APPEND);
		}

		if($locale) {
			$this->domainData = $oldDomainData;
			$this->locale = $oldLocale;
		}

		return $data;

	}

	/**
	 * This method gets called by the translation manager when the default locale
	 * has been changed.
	 * @param      QuioteLocale $newLocale The new default locale.
	 * @since      1.0.0
	 */
	#[\Override]
    public function localeChanged(QuioteLocale $newLocale)
	{
		$this->locale = $newLocale;
		$this->domainData = [];
		$this->pluralFormFunc = null;
	}

	/**
	 * Loads the data from the data file for the given domain with the current
	 * locale.
	 * @param      string $domain The domain to load the data for.
	 * @return     void
	 * @since      1.0.0
	 */
	public function loadDomainData($domain)
	{
		$localeName = $this->locale->getIdentifier();
		$localeNameBases = QuioteLocale::getLookupPath($localeName);

		if(!isset($this->domainPaths[$domain])) {
			if(!$this->domainPathPattern) {
				throw new QuioteException('Using domain "' . $domain . '" which has no path specified');
			} else {
				$basePath = $this->domainPathPattern;
			}
		} else {
			$basePath = $this->domainPaths[$domain];
		}

		$basePath = Toolkit::expandVariables($basePath, ['domain' => $domain]);

		$data = [];

		foreach($localeNameBases as $localeNameBase) {
			$fileName = Toolkit::expandVariables($basePath, ['locale' => $localeNameBase]);
			if($fileName === $basePath) {
				// no replacing of $locale happened
				$fileName = $basePath . '/' . $localeNameBase . '.mo';
			}
			if(is_readable($fileName)) {
				$fileData = GettextMoReader::readFile($fileName);
				
				// instead of array_merge, which doesn't handle null bytes in keys properly. careful, the order matters here.
				$data = $fileData + $data;
			}
		}

		$headers = [];

		if(count($data)) {
			$headerData = str_replace("\r", '', $data['']);
			$headerLines = explode("\n", $headerData);
			foreach($headerLines as $line) {
				$values = explode(':', $line, 2);
				// skip empty / invalid lines
				if(count($values) == 2) {
					$headers[$values[0]] = $values[1];
				}
			}
		}

		$this->pluralFormFunc = null;
		if(isset($headers['Plural-Forms'])) {
			$pf = $headers['Plural-Forms'];
			if(preg_match('#nplurals=\d+;\s+plural=(.*)$#D', $pf, $match)) {
				$funcCode = $match[1];
				$validOpChars = [' ', 'n', '!', '&', '|', '<', '>', '(', ')', '?', ':', ';', '=', '+', '*', '/', '%', '-'];
				if(preg_match('#[^\d' . preg_quote(implode('', $validOpChars), '#') . ']#', $funcCode, $errorMatch)) {
					throw new QuioteException('Illegal character ' . $errorMatch[0] . ' in plural form ' . $funcCode);
				}
				
				// add parenthesis around all ternary expressions. This is done 
				// to make the ternary operator (?) have precedence over the delimiter (:)
				// This will transform 
				// "a ? 1 : b ? c ? 3 : 4 : 2" to "(a ? 1 : (b ? (c ? 3 : 4) : 2))" and
				// "a ? b ? c ? d ? 5 : 4 : 3 : 2 : 1" to "(a ? (b ? (c ? (d ? 5 : 4) : 3) : 2) : 1)"
				// "a ? b ? c ? 4 : 3 : d ? 5 : 2 : 1" to "(a ? (b ? (c ? 4 : 3) : (d ? 5 : 2)) : 1)"
				// "a ? b ? c ? 4 : 3 : d ? 5 : e ? 6 : 2 : 1" to "(a ? (b ? (c ? 4 : 3) : (d ? 5 : (e ? 6 : 2))) : 1)"
				
				$funcCode = rtrim($funcCode, ';');
				$parts = preg_split('#(\?|\:)#', $funcCode, -1, PREG_SPLIT_DELIM_CAPTURE);
				$parenthesisCount = 0;
				$unclosedParenthesisCount = 0;
				$firstParenthesis = true;
				$funcCode = '';
				for($i = 0, $c = count($parts); $i < $c; ++$i) {
					$lastPart = $i > 0 ? $parts[$i - 1] : null;
					$part = $parts[$i];
					$nextPart = $i + 1 < $c ? $parts[$i + 1] : null;
					if($nextPart == '?') {
						if($lastPart == ':') {
							// keep track of parenthesis which need to be closed 
							// directly after this ternary expression
							++$unclosedParenthesisCount;
							--$parenthesisCount;
						}
						$funcCode .= ' (' . $part;
						++$parenthesisCount;
					} elseif($lastPart == ':') {
						$funcCode .= $part . ') ';
						if($unclosedParenthesisCount > 0) {
							$funcCode .= str_repeat(')', $unclosedParenthesisCount);
							$unclosedParenthesisCount = 0;
						}
						--$parenthesisCount;
					} else {
						$funcCode .= $part;
					}
				}
				if($parenthesisCount > 0) {
					// add the missing top level parenthesis
					$funcCode .= str_repeat(')', $parenthesisCount);
				}
				$funcCode .= ';';
				$funcCode = 'return ' . str_replace('n', '$n', $funcCode);
				$this->pluralFormFunc = function ($n) use ($funcCode): void {
                    eval($funcCode);
                };
			}
		}

		$this->domainData[$domain] = ['headers' => $headers, 'msgs' => $data];
	}

	#[\Override]
    public function reset() : void
	{
		$this->context = null;
		$this->domainPathPattern = null;
		$this->domainPaths = [];
		$this->domainData = [];
		$this->locale = null;
		$this->pluralFormFunc = null;
		$this->storeTranslationCalls = false;
		$this->translationCallStoreDir = null;
	}
}
