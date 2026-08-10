<?php
namespace Quiote\Util;

use Quiote\Context;
use Quiote\Config\Config;
use Quiote\Exception\QuioteException;
use Quiote\Exception\ParseException;
use Quiote\Request\WebRequest;
use Quiote\Response\WebResponse;
use Quiote\Util\ParameterHolder;
use Quiote\Util\Toolkit;
use Quiote\Util\FormPopulationConfig;
use Quiote\Util\FormPopulation\DocumentEncoding;
use Quiote\Util\FormPopulation\DocumentLoader;
use Quiote\Util\FormPopulation\DocumentSerializer;
use Quiote\Util\FormPopulation\FieldErrorDecorator;
use Quiote\Util\FormPopulation\FieldNameResolver;
use Quiote\Util\FormPopulation\FieldValueApplier;
use Quiote\Util\FormPopulation\FormFinder;
use Quiote\Util\FormPopulation\SkipList;
use Quiote\Validator\ValidationArgument;
use Quiote\Validator\ValidationIncident;
use Quiote\Validator\ValidationReport;
use Quiote\Validator\Validator;

/**
 * FormPopulationFilter automatically populates a form that is re-posted,
 * which usually happens when a View::INPUT is returned again after a POST
 * request because an error occurred during validation.
 * That means that developers don't have to fill in request parameters into
 * form elements in their templates anymore. Text inputs, selects, radios, they
 * all get set to the value the user selected before submitting the form.
 * If you would like to set default values, you still have to do that in your
 * template. The filter will recognize this situation and automatically remove
 * the default value you assigned after receiving a POST request.
 * This filter only works with POST requests, and compares the form's URL and
 * the requested URL to decide if it's appropriate to fill in a specific form
 * it encounters while processing the output document sent back to the browser.
 * Since this form is executed very late in the process, it works independently
 * of any template language.
 * @since      1.0.0
 * @version    1.0.0
 */
final class FormPopulationEngine
{
	public const string ENCODING_UTF_8 = 'utf-8';

	public const string ENCODING_ISO_8859_1 = 'iso-8859-1';

	private Context $context;

	/**
	 * @var array<string, mixed>
	 */
	private array $parameters = [];

	/**
	 * Our (X)HTML document.
	 */
	protected ?\DOMDocument $doc = null;

	/**
	 * Our XPath instance for the document.
	 */
	protected ?\DOMXPath $xpath = null;

	/**
	 * The XML NS prefix we're working on with XPath, including a colon.
	 */
	protected string $xmlnsPrefix = '';

	/**
	 * Runs an XPath query against $this->xpath and returns the matched nodes
	 * as a plain array. DOMXPath::query() can return false (invalid
	 * expression) or, per its own axis support, namespace nodes; neither of
	 * those are ever produced by the element/attribute-only expressions used
	 * throughout this class, so both are normalized away here to keep the
	 * calling code working with plain DOMNode instances.
	 * @return     array<int, \DOMNode>
	 * @since      1.0.0
	 */
	protected function queryNodes(string $expression, ?\DOMNode $contextNode = null): array
	{
		if($this->xpath === null) {
			return [];
		}

		$result = $this->xpath->query($expression, $contextNode);
		if($result === false) {
			return [];
		}

		$nodes = [];
		foreach($result as $node) {
			if($node instanceof \DOMNode) {
				$nodes[] = $node;
			}
		}
		return $nodes;
	}

	/**
	 * Like queryNodes(), but narrowed to \DOMElement, for expressions that are
	 * only ever expected to select elements.
	 * @return     array<int, \DOMElement>
	 * @since      1.0.0
	 */
	protected function queryElements(string $expression, ?\DOMNode $contextNode = null): array
	{
		$elements = [];
		foreach($this->queryNodes($expression, $contextNode) as $node) {
			if($node instanceof \DOMElement) {
				$elements[] = $node;
			}
		}
		return $elements;
	}

	/**
	 * Narrow a mixed config value to int, falling back to the given default.
	 */
	private function cfgInt(mixed $value, int $default): int
	{
		return is_int($value) ? $value : $default;
	}

	/**
	 * Coerce a mixed value (config value, DOM/document content, etc.) to a
	 * string using the same scalar/Stringable rule PHP's own (string) cast
	 * uses, falling back to $default for values that can't be meaningfully
	 * stringified (arrays, non-Stringable objects).
	 */
	private function toScalarString(mixed $value, string $default = ''): string
	{
		if(is_string($value)) {
			return $value;
		}
		if(is_scalar($value) || $value instanceof \Stringable) {
			return (string) $value;
		}
		return $default;
	}

	/**
	 * Populate the provided response content with request data and validation errors.
	 * @param      array<string, mixed> $overrides
	 */
	public function populate(WebResponse $response, WebRequest $request, array $overrides = []): void
	{
		if(!isset($this->context)) {
			throw new \LogicException('FormPopulationEngine must be initialized before use.');
		}
		if(!$response->isContentMutable() || !($output = $response->getContent())) {
			return;
		}

		// Ensure the request has been seeded with default config (only runs once per request)
		$request = $this->ensureSeedInitialized($request);

		$cfg = $this->buildConfiguration($request, $overrides);

		$ot = $response->getOutputType();
		if($ot === null) {
			return;
		}

		if(is_array($cfg['output_types']) && !in_array($ot->getName(), $cfg['output_types'])) {
			return;
		}

		$populate = $this->resolvePopulateSource($request, $cfg);
		if($populate === null) {
			return;
		}

		$skip = SkipList::fromConfig($cfg['skip']);

		$forceRequestUri = $cfg['force_request_uri'];
		if($forceRequestUri !== false && is_string($forceRequestUri)) {
			$ruri = $forceRequestUri;
		} else {
			$ruri = $this->resolveRequestUri($request);
		}
		$forceRequestUrl = $cfg['force_request_url'];
		if($forceRequestUrl !== false && is_string($forceRequestUrl)) {
			$rurl = $forceRequestUrl;
		} else {
			$rurl = $this->resolveRequestUrl($request, $ruri);
		}

		if(isset($cfg['validation_report']) && $cfg['validation_report'] instanceof ValidationReport) {
			$vr = $cfg['validation_report'];
		} else {
			$vr = new ValidationReport();
		}

		$errorMessageRules = [];
		if(isset($cfg['error_messages']) && is_array($cfg['error_messages'])) {
			$errorMessageRules = $cfg['error_messages'];
		}
		$fieldErrorMessageRules = $errorMessageRules;
		if(isset($cfg['field_error_messages']) && is_array($cfg['field_error_messages']) && count($cfg['field_error_messages'])) {
			$fieldErrorMessageRules = $cfg['field_error_messages'];
		}
		$multiFieldErrorMessageRules = $fieldErrorMessageRules;
		if(isset($cfg['multi_field_error_messages']) && is_array($cfg['multi_field_error_messages']) && count($cfg['multi_field_error_messages'])) {
			$multiFieldErrorMessageRules = $cfg['multi_field_error_messages'];
		}

		$loaded = (new DocumentLoader(\Quiote\Logging\Log::for($this)))->load(
			$this->toScalarString($output),
			$cfg,
			$this->toScalarString($ot->getParameter('http_headers[Content-Type]')) ?: null
		);
		if($loaded === null) {
			// a fatal parse error; there is no usable tree to populate
			return;
		}

		$this->doc = $loaded->document;
		$this->xpath = $loaded->xpath;
		$this->xmlnsPrefix = $loaded->xmlnsPrefix;
		$xhtml = $loaded->isXhtml;
		$hasXmlProlog = $loaded->hadXmlProlog;

		$properXhtml = false;
		foreach($this->queryElements(sprintf('//%1$shead/%1$smeta', $this->xmlnsPrefix)) as $meta) {
			if(strtolower($meta->getAttribute('http-equiv')) == 'content-type') {
				if($this->doc->encoding === null) {
					// media-type = type "/" subtype *( ";" parameter ), says http://www.w3.org/Protocols/rfc2616/rfc2616-sec3.html#sec3.7
					if(preg_match('/;\s*charset=(")?(?P<charset>.+?(?(1)(?=(?<!\\\\)")|($|(?=[;\s]))))(?(1)")/i', $meta->getAttribute('content'), $matches)) {
						$this->doc->encoding = $matches['charset'];
					} else {
						$this->doc->encoding = self::ENCODING_UTF_8;
					}
				}
				if(str_contains($meta->getAttribute('content'), 'application/xhtml+xml')) {
					$properXhtml = true;
				}
				break;
			}
		}

		$forceEncoding = $cfg['force_encoding'];
		if($forceEncoding === false) {
			if($this->doc->encoding) { // doc->actualEncoding is deprecated in PHP 8.4
				$encoding = $this->doc->encoding;
			} else {
				$encoding = self::ENCODING_UTF_8;
				$this->doc->encoding = $encoding;
			}
		} else {
			$encoding = $this->toScalarString($forceEncoding, self::ENCODING_UTF_8);
			$this->doc->encoding = $encoding;
		}
		$documentEncoding = DocumentEncoding::named($encoding);
		$encoding = $documentEncoding->name;
		$utf8 = $documentEncoding->isUtf8;

		$base = $this->queryElements(sprintf('/%1$shtml/%1$shead/%1$sbase[@href]', $this->xmlnsPrefix));
		if($base) {
			$baseHref = $base[0]->getAttribute('href');
		} else {
			$baseHref = '';
		}
		$baseHref = substr((string) $baseHref, 0, strrpos((string) $baseHref, '/') + 1);

		$formFinder = new FormFinder(
			fn(string $expression, ?\DOMElement $contextNode = null): array => $this->queryElements($expression, $contextNode),
			$this->xmlnsPrefix
		);
		$forms = $formFinder->find($populate, $cfg);

		$decorator = new FieldErrorDecorator(
			fn(string $expression, ?\DOMElement $contextNode = null): array => $this->queryElements($expression, $contextNode),
			$this->xmlnsPrefix
		);

		$applier = new FieldValueApplier(
			$this->doc,
			$documentEncoding,
			$this->xmlnsPrefix,
			$xhtml && $properXhtml,
			(bool) $cfg['include_hidden_inputs'],
			(bool) $cfg['include_password_inputs'],
			fn(string $expression, ?\DOMElement $contextNode = null): array => $this->queryElements($expression, $contextNode),
		);

		// an array of all validation incidents; errors inserted for fields or multiple fields will be removed in here
		$allIncidents = $vr->getIncidents();

		foreach($forms as $form) {
			$p = $formFinder->dataFor(
				$form,
				$populate,
				$ruri,
				$rurl,
				$baseHref,
				$this->createParameterHolderFromRequest($request)
			);
			if($p === null) {
				continue;
			}

			$fieldNames = new FieldNameResolver();

			// build the XPath query
			// we select descendants of the given form
			// as well as any element in the document associated with the form using a "form" attribute that contains the ID of the current form
			// provided they match the following criteria:
			// * <textarea> with a "name" attribute
			// * <select> with a "name" attribute
			// * <button type="submit"> with a "name" attribute
			// * <input> with a "name" attribute except for the following:
			//  * <input type="checkbox"> elements with a "name" attribute that contains the character sequence "[]" and no "value" attribute
			//  * <input type="hidden"> unless config option "include_hidden_inputs" is true (defaults to true)
			$query = sprintf('
				descendant::%1$stextarea[@name] |
				descendant::%1$sselect[@name] |
				descendant::%1$sbutton[@name and @type="submit"] |
				descendant::%1$sinput[@name and (not(@type="checkbox") or (not(contains(@name, "[]")) or (contains(@name, "[]") and @value)))]',
				$this->xmlnsPrefix
			);
			
			if(($formId = $form->hasAttribute('id')) != "") {
				// find elements associated with this form as well
				$query .= sprintf(' |
					//%1$stextarea[@form="%2$s" and @name] |
					//%1$sselect[@form="%2$s" and @name] |
					//%1$sbutton[@form="%2$s" and @name and @type="submit"] |
					//%1$sinput[@form="%2$s" and @name and (not(@type="checkbox") or (not(contains(@name, "[]")) or (contains(@name, "[]") and @value)))]',
					$this->xmlnsPrefix,
					$formId
				);
			}
			
			foreach($this->queryElements($query, $form) as $element) {

				$name = $element->getAttribute('name');

				$multiple = $element->nodeName == 'select' && $element->hasAttribute('multiple');

				$elementType = $element->getAttribute('type');
				$resolvedName = $fieldNames->resolve(
					$name,
					$elementType == 'checkbox' || $elementType == 'radio',
					$multiple
				);
				if($resolvedName === null) {
					// foo[][3] checkboxes etc not possible, [] must occur only once and at the end
					continue;
				}
				$pname = $resolvedName->path;
				$checkValue = $resolvedName->groupsByValue;

				if(!$utf8) {
					$pname = $this->toScalarString($this->fromUtf8($pname, $encoding), $pname);
				}

				if($skip->skips($pname . ($checkValue ? '[]' : ''))) {
					// skip field
					continue;
				}

				$argument = new ValidationArgument(
					$pname,
					($element->nodeName == 'input' && $element->getAttribute('type') == 'file')
						? "files" : "parameters"
				);
				
				// there's an error with the element's name in the request? good. let's give the baby a class!
				if($vr->getAuthoritativeArgumentSeverity($argument) > Validator::SILENT) {
					$decorator->decorate(
						$element,
						$form,
						is_array($cfg['error_class_map']) ? $cfg['error_class_map'] : []
					);

					// up next: the error messages
					$fieldIncidents = [];
					$multiFieldIncidents = [];
					// grab all incidents for this field
					foreach($vr->byArgument($argument)->getIncidents() as $incident) {
						if(($incidentKey = array_search($incident, $allIncidents, true)) !== false) {
							// does this one have more than one field?
							// and is it really more than one parameter or file, not a cookie or header?
							$incidentArgumentCount = 0;
							$incidentArguments = $incident->getArguments();
							foreach($incidentArguments as $incidentArgument) {
								if(in_array($incidentArgument->getSource(), ["files", "parameters"])) {
									$incidentArgumentCount++;
								}
							}
							if($incidentArgumentCount > 1) {
								$multiFieldIncidents[] = $incident;
							} else {
								$fieldIncidents[] = $incident;
							}
							// remove it from the list of all incidents
							unset($allIncidents[$incidentKey]);
						}
					}
					// 1) insert error messages that are specific to this field
					if(!$this->insertErrorMessages($element, $fieldErrorMessageRules, $fieldIncidents)) {
						$allIncidents = array_merge($allIncidents, $fieldIncidents);
					}
					// 2) insert error messages that belong to multiple fields (including this one), if that message was not inserted before
					if(!$this->insertErrorMessages($element, $multiFieldErrorMessageRules, $multiFieldIncidents)) {
						$allIncidents = array_merge($allIncidents, $multiFieldIncidents);
					}
				}

				// FPF only handles "normal" values, as file inputs cannot be re-populated, so getParameter() with no source-specific stuff is fine here
				$value = $p->getParameter($pname);

				if(is_array($value) && !($element->nodeName == 'select' || $checkValue)) {
					// name didn't match exactly. skip.
					continue;
				}

				if(is_bool($value)) {
					$value = (string)(int)$value;
				} elseif(!$utf8) {
					$value = $this->toUtf8($value, $encoding);
				} else {
					if(is_array($value)) {
						$value = array_map(fn(mixed $v): string => $this->toScalarString($v), $value);
					} else {
						$value = $this->toScalarString($value);
					}
				}

				$applier->apply($element, $resolvedName, $value, $p);

			}

			// now output the remaining incidents
			// might include errors for cookies, headers and whatnot, but that is okay
			if($this->insertErrorMessages($form, $errorMessageRules, $allIncidents)) {
				$allIncidents = [];
			}
		}

		$updatedRequest = FormPopulationConfig::setScopedValue($request, 'orphaned_errors', $allIncidents);
		if ($updatedRequest instanceof WebRequest) {
			$request = $updatedRequest;
			try {
				$this->context->getContainer()->get(\Quiote\Request\RequestState::class)->publish($request);
			} catch (\Throwable $e) {
				// The repopulated request never reached the context, so anything reading it
				// afterwards sees the pre-population values.
				\Quiote\Logging\Log::for($this)->warning(
					'[FormPopulationEngine] could not publish the repopulated request to the context: '
					. $e->getMessage()
				);
			}
		}

		$serializer = new DocumentSerializer(
			$this->doc,
			(bool) ($xhtml && $cfg['parse_xhtml_as_xml']),
			$properXhtml,
			$hasXmlProlog,
			$utf8
		);
		$response->setContent($serializer->serialize($xhtml, $cfg));

		unset($this->xpath);
		unset($this->doc);
	}

	/**
	 * Whether this engine runs after the response body has been produced.
	 *
	 * Always true: population rewrites the finished (X)HTML document, so it can
	 * only run once the view has rendered.
	 */
	public function isPostFilter(): bool
	{
		return true;
	}

	/**
	 * Insert the error messages from the given incidents into the given element
	 * using the given rules.
	 * @param      \DOMElement $element The element to work on.
	 * @param      array<string, mixed> $rules An array of insertion rules
	 * @param      array<int, ValidationIncident> $incidents An array of ValidationIncidents.
	 * @return     bool Whether or not the inserts were successful.
	 * @since      1.0.0
	 */
	protected function insertErrorMessages(\DOMElement $element, array $rules, array $incidents)
	{
		$errors = [];
		foreach($incidents as $incident) {
			if($incident->getSeverity() <= Validator::SILENT) {
				continue;
			}
			foreach($incident->getErrors() as $error) {
				if(strlen((string) $error->getMessage())) {
					$errors[] = $error;
				}
			}
		}
		
		if(!$errors) {
			// nothing to do here
			return true;
		}

		if($this->doc === null) {
			throw new \LogicException('insertErrorMessages() called without an active document; populate() must run first.');
		}
		$doc = $this->doc;

		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$insertSuccessful = false;
		foreach($rules as $xpathExpression => $errorMessageInfo) {
			$targets = $this->queryNodes(Toolkit::expandVariables($xpathExpression, ['htmlnsPrefix' => $this->xmlnsPrefix]), $element);

			if(!$targets) {
				continue;
			}

			if(!is_array($errorMessageInfo)) {
				$errorMessageInfo = ['markup' => $errorMessageInfo];
			}
			if(isset($errorMessageInfo['markup'])) {
				$errorMarkup = $errorMessageInfo['markup'];
			} else {
				$errorMarkup = null;
			}
			if(isset($errorMessageInfo['location'])) {
				$errorLocation = $errorMessageInfo['location'];
			} else {
				$errorLocation = 'after';
			}
			if(isset($errorMessageInfo['container'])) {
				$errorContainer = $errorMessageInfo['container'];
			} else {
				$errorContainer = null;
			}
			
			if(!$errorMarkup && !$errorContainer) {
				throw new QuioteException('Form Population Filter was unable to insert error messages into the document using the XPath expression "' . $xpathExpression . '" because the element information did not contain either a "markup" or "container" entry to use.');
			}
			
			$errorElements = [];
			
			if($errorMarkup) {
				foreach($errors as $error) {
					if(is_callable($errorMarkup)) {
						// it's a callback we can use to get a DOMElement or an XML/HTML string (for convenience
						// and because it is impossible to provide multiple sibling elements via a DOMElement)
						// we give it the element as the first, the error message as the second (for BC reasons)
						// and the error object as the third argument
						$errorElement = call_user_func($errorMarkup, $element, $error->getMessage(), $error);
						if(is_string($errorElement)) {
							$errorElementHtml = $errorElement;
							$errorElement = $doc->createDocumentFragment();
							$errorElement->appendXML($errorElementHtml);
						} else {
							$doc->importNode($errorElement, true);
						}
					} elseif(is_string($errorMarkup)) {
						// it's a string with the HTML to insert
						// %s is the placeholder in the HTML for the error message
						$errorElement = $doc->createDocumentFragment();
						$errorElement->appendXML(
							Toolkit::expandVariables(
								$errorMarkup,
								[
									'elementId'    => htmlspecialchars($element->getAttribute('id'), ENT_QUOTES, 'UTF-8'),
									'elementName'  => htmlspecialchars($element->getAttribute('name'), ENT_QUOTES, 'UTF-8'),
									'errorMessage' => htmlspecialchars((string) $error->getMessage(), ENT_QUOTES, 'UTF-8'),
								]
							)
						);
					} else {
						throw new QuioteException('Form Population Filter was unable to insert an error message into the document using the XPath expression "' . $xpathExpression . '" because the element information could not be evaluated as an XML/HTML fragment or as a PHP callback.');
					}
					
					$errorElements[] = $errorElement;
				}
			}

			if($errorContainer) {
				// we have an error container.
				// that means that instead of inserting each message element, we add the messages into the container
				// then, the container is the only element scheduled for insertion
				$errorStrings = [];
				if($errorElements) {
					// add all error XML strings to an array
					foreach($errorElements as $errorElement) {
						$errorStrings[] = $errorElement->ownerDocument->saveXML($errorElement);
					}
				} else {
					// if no error markup was given, just provide the error messages
					foreach($errors as $error) {
						$errorStrings[] = $error->getMessage();
					}
				}

				// create the container element and replace the errors placeholder in the container
				if(is_callable($errorContainer)) {
					// it's a callback we can use to get a DOMElement or an XML/HTML string (for convenience
					// and because it is impossible to provide multiple sibling elements via a DOMElement)
					// we give it the element as the first, the error messages array(!) as the second (for BC reasons)
					// and the array of all error objects as the third argument
					$containerElement = call_user_func($errorContainer, $element, $errorStrings, $errors);
					if(is_string($containerElement)) {
						$containerElementHtml = $containerElement;
						$containerElement = $doc->createDocumentFragment();
						$containerElement->appendXML($containerElementHtml);
					} else {
						$doc->importNode($containerElement, true);
					}
				} elseif(is_string($errorContainer)) {
					// it's a string with the HTML to insert
					// %s is the placeholder in the HTML for the error message
					$containerElement = $doc->createDocumentFragment();
					$containerElement->appendXML(
						Toolkit::expandVariables(
							$errorContainer,
							[
								'elementId'     => htmlspecialchars($element->getAttribute('id'), ENT_QUOTES, 'UTF-8'),
								'elementName'   => htmlspecialchars($element->getAttribute('name'), ENT_QUOTES, 'UTF-8'),
								'errorMessages' => implode("\n", $errorStrings),
							]
						)
					);
				} else {
					throw new QuioteException('Form Population Filter was unable to insert an error message container into the document using the XPath expression "' . $xpathExpression . '" because the element information could not be evaluated as an XML/HTML fragment or as a PHP callback.');
				}

				// and now the trick: set the error container element as the only one in the errorElements variable
				// that way, it's going to get inserted for us as if it were a normal error message element, using the location specified
				$errorElements = [$containerElement];
			}

			if(libxml_get_last_error() !== false) {
				$errors = [];
				foreach(libxml_get_errors() as $error) {
					$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
				}
				libxml_clear_errors();
				libxml_use_internal_errors($luie);
				$emsg = sprintf(
					'Form Population Filter was unable to insert an error message into the document using the XPath expression "%s" due to the following error%s: ' . "\n\n%s",
					$xpathExpression,
					count($errors) > 1 ? 's' : '',
					implode("\n", $errors)
				);
				throw new ParseException($emsg);
			}

			foreach($errorElements as $errorElement) {
				foreach($targets as $target) {
					// in case the target yielded more than one location, we need to clone the element
					// because the document fragment node will be corrupted after an insert
					$clonedErrorElement = $errorElement->cloneNode(true);
					$targetParent = $target->parentNode;

					if($targetParent === null) {
						// the target has no parent to insert relative to (e.g. it is a
						// detached node or the document element itself), so fall back
						// to appending the error directly onto the target
						$target->appendChild($clonedErrorElement);
					} elseif($errorLocation == 'before') {
						$targetParent->insertBefore($clonedErrorElement, $target);
					} elseif($errorLocation == 'after') {
						// check if there is a following sibling, then insert before that one
						// if not, append to parent
						if($target->nextSibling) {
							$targetParent->insertBefore($clonedErrorElement, $target->nextSibling);
						} else {
							$targetParent->appendChild($clonedErrorElement);
						}
					} elseif($errorLocation == 'replace') {
						$targetParent->replaceChild($clonedErrorElement, $target);
					} else {
						$target->appendChild($clonedErrorElement);
					}
				}
			}

			// and break the foreach, our expression matched after all - no need to look further
			$insertSuccessful = true;
			break;
		}

		libxml_clear_errors();
		libxml_use_internal_errors($luie);

		return $insertSuccessful;
	}

	/**
	 * Encode given value to UTF-8
	 * @param      mixed $value The value to convert (can be an array).
	 * @param      string $encoding The encoding of the value.
	 * @return     mixed  The converted value.
	 * @since      1.0.0
	 */
	protected function toUtf8($value, $encoding = self::ENCODING_ISO_8859_1)
	{
		return DocumentEncoding::named($this->toScalarString($encoding, self::ENCODING_ISO_8859_1))->toUtf8($value);
	}

	/**
	 * Decode given value from UTF-8
	 * @param      mixed $value The value to convert (can be an array).
	 * @param      string $encoding The encoding of the value.
	 * @return     mixed  The converted value.
	 * @since      1.0.0
	 */
	protected function fromUtf8($value, $encoding = self::ENCODING_ISO_8859_1)
	{
		return DocumentEncoding::named($this->toScalarString($encoding, self::ENCODING_ISO_8859_1))->fromUtf8($value);
	}

	/**
	 * Initialize this engine.
	 * @param      Context $context The current application context.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @since      1.0.0
	 */
    public function initialize(Context $context, array $parameters = []): void
	{
		$this->context = $context;
		$this->parameters = $this->defaultParameters();
		if($parameters) {
			$this->parameters = array_replace($this->parameters, $parameters);
		}
		$this->parameters = $this->normalizeParameters($this->parameters);

		// Note: FormPopulationConfig::seed() is called in ensureSeedInitialized()
		// rather than here, since the request may not exist yet when middleware is initialized
	}

	/**
	 * Ensure the request has been seeded with default form population config.
	 * This is called lazily during populate() rather than during initialize()
	 * because the request may not exist when middleware is being set up.
	 * Note: seed() is idempotent - it only fills missing keys, doesn't overwrite.
	 */
	private function ensureSeedInitialized(WebRequest $request): WebRequest
	{
		// Always seed - the seed() method itself is idempotent and won't overwrite
		// existing values, only fill in defaults for missing keys
		$seeded = FormPopulationConfig::seed($request, $this->parameters);
		return $seeded instanceof WebRequest ? $seeded : $request;
	}

	/**
	 * Drops the per-response DOM state so the engine can serve the next request.
	 *
	 * Releases the parsed document, its XPath instance and the resolved XML
	 * namespace prefix. The configured parameters are kept -- they come from
	 * configuration, not from the request being populated.
	 */
	public function reset(): void
	{
		$this->doc = null;
		$this->xpath = null;
		$this->xmlnsPrefix = '';
	}

	/** @return array<string, mixed> */
	public function getDefaults(): array
	{
		return $this->parameters;
	}

	/** @return array<string, mixed> */
	private function defaultParameters(): array
	{
		return [
			'methods'                    => [],
			'output_types'               => null,
			'forms_xpath'                => '//${htmlnsPrefix}form[@action]',
			'populate'                   => null,
			'skip'                       => null,
			'include_hidden_inputs'      => true,
			'include_password_inputs'    => false,
			'force_output_mode'          => false,
			'force_encoding'             => false,
			'force_request_uri'          => false,
			'force_request_url'          => false,
			'cdata_fix'                  => true,
			'parse_xhtml_as_xml'         => true,
			'remove_auto_xml_prolog'     => true,
			'dom_substitute_entities'    => false,
			'dom_resolve_externals'      => false,
			'dom_validate_on_parse'      => false,
			'dom_preserve_white_space'   => true,
			'dom_format_output'          => false,
			'savexml_options'            => [],
			'error_class'                => 'error',
			'error_class_map'            => [],
			'error_messages'             => [],
			'field_error_messages'       => [],
			'multi_field_error_messages' => [],
			'ignore_parse_errors'        => LIBXML_ERR_ERROR,
			'log_parse_errors'           => LIBXML_ERR_WARNING,
			'logging_logger'             => null,
		];
	}

	/**
	 * @param      array<string, mixed> $parameters
	 * @return     array<string, mixed>
	 */
	private function normalizeParameters(array $parameters): array
	{
		$errorClassMap = (array) ($parameters['error_class_map'] ?? []);
		$errorClassMap['self::${htmlnsPrefix}*'] = $parameters['error_class'] ?? 'error';
		$parameters['error_class_map'] = $errorClassMap;

		$parameters['methods'] = (array) ($parameters['methods'] ?? []);

		if(isset($parameters['output_types']) && $parameters['output_types']) {
			$parameters['output_types'] = (array) $parameters['output_types'];
		} else {
			$parameters['output_types'] = null;
		}

		$savexmlOptions = 0;
		foreach((array) ($parameters['savexml_options'] ?? []) as $option) {
			if(is_numeric($option)) {
				$savexmlOptions |= (int) $option;
			} elseif(is_string($option) && defined($option)) {
				$savexmlOptions |= $this->cfgInt(constant($option), 0);
			}
		}
		$parameters['savexml_options'] = $savexmlOptions;

		$parameters['ignore_parse_errors'] = $this->normalizeLibxmlLevel($parameters['ignore_parse_errors'] ?? LIBXML_ERR_ERROR, true);
		$parameters['log_parse_errors'] = $this->normalizeLibxmlLevel($parameters['log_parse_errors'] ?? LIBXML_ERR_WARNING, false);

		return $parameters;
	}

	private function normalizeLibxmlLevel(mixed $value, bool $isIgnoreSetting): int|false
	{
		if(is_string($value) && defined($value)) {
			$value = constant($value);
		}
		if($isIgnoreSetting) {
			if($value === true) {
				return LIBXML_ERR_FATAL;
			}
			if($value === false) {
				return LIBXML_ERR_NONE;
			}
		} else {
			if($value === true) {
				return LIBXML_ERR_WARNING;
			}
			if($value === false) {
				return false;
			}
		}
		if(is_int($value)) {
			return $value;
		}
		return $isIgnoreSetting ? LIBXML_ERR_ERROR : LIBXML_ERR_WARNING;
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function buildConfiguration(WebRequest $request, array $overrides): array
	{
		$config = array_replace($this->parameters, FormPopulationConfig::get($request));
		if($overrides) {
			$config = array_replace($config, $overrides);
		}
		// $this->parameters was already normalized once in initialize(). The
		// common case -- no overrides, and the request carries nothing beyond
		// the seeded defaults -- leaves $config identical to it, so
		// re-running normalizeParameters() on every populate() call would just
		// recompute the same result. Only pay for it when something actually
		// differs from the memoized defaults.
		if($config === $this->parameters) {
			return $config;
		}
		return $this->normalizeParameters($config);
	}

	/**
	 * Resolve populate configuration into parameter holders understood by the processor.
	 * @param mixed $request
	 * @param array<string, mixed> $cfg
	 * @return mixed
	 */
	protected function resolvePopulateSource($request, array $cfg)
	{
		$populateConfig = $cfg['populate'] ?? null;
		if(is_array($populateConfig)) {
			$result = [];
			foreach($populateConfig as $key => $value) {
				$holder = null;
				if($value instanceof ParameterHolder) {
					$holder = $value;
				} elseif($value === true) {
					$holder = $this->createParameterHolderFromRequest($request);
				} elseif(is_array($value)) {
					$holder = new ParameterHolder($value);
				}
				if($holder instanceof ParameterHolder && is_string($key) && $key !== '') {
					$result[$key] = $holder;
				}
			}
			return $result;
		}

		if($populateConfig instanceof ParameterHolder) {
			return $populateConfig;
		}

		$methods = [];
		$allowAllMethods = true;
		if(isset($cfg['methods']) && is_array($cfg['methods']) && count($cfg['methods'])) {
			$allowAllMethods = false;
			foreach($cfg['methods'] as $method) {
				if(!is_string($method) || $method === '') {
					continue;
				}
				$upper = strtoupper($method);
				if($upper === 'ANY' || $upper === '*') {
					$methods = [];
					$allowAllMethods = true;
					break;
				}
				if($upper === 'WRITE') {
					$methods = array_merge($methods, ['WRITE', 'POST', 'PUT', 'PATCH', 'DELETE']);
					continue;
				}
				if($upper === 'READ') {
					$methods = array_merge($methods, ['READ', 'GET', 'HEAD', 'OPTIONS']);
					continue;
				}
				$methods[] = $upper;
			}
		}
		if(!$allowAllMethods) {
			$methods = array_values(array_unique($methods));
		}

		$requestMethod = null;
		if(is_object($request) && method_exists($request, 'getMethod')) {
			$probed = $this->probe('getMethod', fn(): string => strtoupper((string) $request->getMethod()));
			if($probed !== null) {
				$requestMethod = $probed;
			}
		}
		$methodAllowed = $allowAllMethods ? true : ($requestMethod !== null && in_array($requestMethod, $methods, true));

		if($populateConfig === true || ($methodAllowed && $populateConfig !== false)) {
			$holder = $this->createParameterHolderFromRequest($request);
			if($holder instanceof ParameterHolder) {
				return $holder;
			}
		}

		return null;
	}

	/**
	 * Create a parameter holder from the given request-like object.
	 * @param mixed $request
	 */
	protected function createParameterHolderFromRequest($request): ?ParameterHolder
	{
		if($request instanceof ParameterHolder) {
			return $request;
		}
		if($request instanceof WebRequest) {
			try {
				$params = $request->getParameters();
			} catch(\Throwable) {
				$params = [];
			}
			return new ParameterHolder($params);
		}
		if(is_object($request) && method_exists($request, 'getParameters')) {
			try {
				$params = $request->getParameters();
			} catch(\Throwable) {
				$params = null;
			}
			if($params instanceof ParameterHolder) {
				return $params;
			}
			if(is_array($params)) {
				return new ParameterHolder($params);
			}
		}
		return null;
	}

	/**
	 * @param mixed $request
	 */
	protected function resolveRequestUri($request): string
	{
		if($request instanceof WebRequest) {
			return $this->probe('getRequestUri', fn(): string => (string) $request->getRequestUri())
				?? $this->probe('getUrlPath', fn(): string => (string) $request->getUrlPath())
				?? $this->probeAttribute($request, 'request_uri')
				?? '/';
		}

		return $this->probeAttribute($request, 'request_uri') ?? '/';
	}

	/**
	 * The value $source produces, or null when it is empty or could not be read.
	 *
	 * These resolvers walk a cascade of places a request URI or URL might live, and "this one
	 * did not answer" is the ordinary case that moves to the next candidate -- so a failure is
	 * recorded at debug level rather than raised. Without the record, a request whose every
	 * candidate throws silently populates forms against "/" and the reason is invisible.
	 *
	 * @param      callable(): string $source
	 */
	private function probe(string $label, callable $source): ?string
	{
		try {
			$value = $source();
		} catch(\Throwable $e) {
			\Quiote\Logging\Log::for($this)->debug(
				'[FormPopulationEngine] request source "' . $label . '" unavailable, trying the next: '
				. $e->getMessage()
			);

			return null;
		}

		return $value !== '' ? $value : null;
	}

	/**
	 * A string request attribute, or null when absent, empty or unreadable.
	 * @param      mixed $request
	 */
	private function probeAttribute($request, string $name): ?string
	{
		if(!is_object($request) || !method_exists($request, 'getAttribute')) {
			return null;
		}

		return $this->probe('attribute:' . $name, function () use ($request, $name): string {
			$attr = $request->getAttribute($name);

			return is_string($attr) ? $attr : '';
		});
	}

	/**
	 * @param mixed $request
	 */
	protected function resolveRequestUrl($request, string $fallbackUri): string
	{
		if($request instanceof WebRequest) {
			$url = $this->probe('getUrl', fn(): string => (string) $request->getUrl());
			if($url !== null) {
				return $url;
			}
		}

		return $this->probeAttribute($request, 'request_url') ?? $fallbackUri;
	}
}

?>
