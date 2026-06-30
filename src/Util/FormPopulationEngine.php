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
namespace Agavi\Util;

use Agavi\AgaviContext;
use Agavi\Config\AgaviConfig;
use Agavi\Exception\AgaviException;
use Agavi\Exception\AgaviParseException;
use Agavi\Logging\AgaviLogger;
use Agavi\Request\AgaviWebRequest;
use Agavi\Response\AgaviWebResponse;
use Agavi\Util\AgaviParameterHolder;
use Agavi\Util\AgaviToolkit;
use Agavi\Util\FormPopulationConfig;
use Agavi\Validator\AgaviValidationArgument;
use Agavi\Validator\AgaviValidationReport;
use Agavi\Validator\AgaviValidator;

/**
 * AgaviFormPopulationFilter automatically populates a form that is re-posted,
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
 *
 * @package    agavi
 * @subpackage filter
 *
 * @author     David Zülke <dz@bitxtender.com>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.11.0
 *
 * @version    $Id$
 */
final class FormPopulationEngine
{
	public const string ENCODING_UTF_8 = 'utf-8';

	public const string ENCODING_ISO_8859_1 = 'iso-8859-1';

	private AgaviContext $context;

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
	 * Populate the provided response content with request data and validation errors.
	 */
	public function populate(AgaviWebResponse $response, AgaviWebRequest $request, array $overrides = []): void
	{
		if(!isset($this->context)) {
			throw new \LogicException('FormPopulationEngine must be initialized before use.');
		}
		if(!$response->isContentMutable() || !($output = $response->getContent())) {
			return;
		}

		// Ensure the request has been seeded with default config (only runs once per request)
		$this->ensureSeedInitialized($request);

		$cfg = $this->buildConfiguration($request, $overrides);

		$ot = $response->getOutputType();

		if(is_array($cfg['output_types']) && !in_array($ot->getName(), $cfg['output_types'])) {
			return;
		}

		$populate = $this->resolvePopulateSource($request, $cfg);
		if($populate === null) {
			return;
		}

		$skip = null;
		if($cfg['skip'] instanceof AgaviParameterHolder) {
			$cfg['skip'] = $cfg['skip']->getParameters();
		} elseif($cfg['skip'] !== null && !is_array($cfg['skip'])) {
			$cfg['skip'] = null;
		}
		if($cfg['skip'] !== null && count($cfg['skip'])) {
			$skip = '/(\A' . str_replace('\[\]', '\[[^\]]*\]', implode('|\A', array_map(preg_quote(...), $cfg['skip'], array_fill(0, count($cfg['skip']), '/')))) . ')/';
		}

		if($cfg['force_request_uri'] !== false) {
			$ruri = $cfg['force_request_uri'];
		} else {
			$ruri = $this->resolveRequestUri($request);
		}
		if($cfg['force_request_url'] !== false) {
			$rurl = $cfg['force_request_url'];
		} else {
			$rurl = $this->resolveRequestUrl($request, $ruri);
		}

		if(isset($cfg['validation_report']) && $cfg['validation_report'] instanceof AgaviValidationReport) {
			$vr = $cfg['validation_report'];
		} else {
			$vr = new AgaviValidationReport();
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

		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$this->doc = new \DOMDocument();

		$this->doc->substituteEntities = $cfg['dom_substitute_entities'];
		$this->doc->resolveExternals   = $cfg['dom_resolve_externals'];
		$this->doc->validateOnParse    = $cfg['dom_validate_on_parse'];
		$this->doc->preserveWhiteSpace = $cfg['dom_preserve_white_space'];
		$this->doc->formatOutput       = $cfg['dom_format_output'];

		$xhtml = (preg_match('/<!DOCTYPE[^>]+XHTML[^>]+/', (string) $output) > 0 && strtolower((string) $cfg['force_output_mode']) != 'html') || strtolower((string) $cfg['force_output_mode']) == 'xhtml';

		$hasXmlProlog = false;
		if($xhtml && preg_match('/^<\?xml[^\?]*\?>/', (string) $output)) {
			$hasXmlProlog = true;
		} elseif($xhtml && preg_match('/;\s*charset=(")?(?P<charset>.+?(?(1)(?=(?<!\\\\)")|($|(?=[;\s]))))(?(1)")/i', (string) $ot->getParameter('http_headers[Content-Type]'), $matches)) {
			// media-type = type "/" subtype *( ";" parameter ), says http://www.w3.org/Protocols/rfc2616/rfc2616-sec3.html#sec3.7
			// add an XML prolog with the char encoding, works around issues with ISO-8859-1 etc
			$output = "<?xml version='1.0' encoding='" . $matches['charset'] . "' ?>\n" . $output;
		}

		if($xhtml && $cfg['parse_xhtml_as_xml']) {
			$this->doc->loadXML($output);
			$this->xpath = new \DomXPath($this->doc);
			if($this->doc->documentElement && $this->doc->documentElement->namespaceURI) {
				$this->xpath->registerNamespace('html', $this->doc->documentElement->namespaceURI);
				$this->xmlnsPrefix = 'html:';
			} else {
				$this->xmlnsPrefix = '';
			}
		} else {
			$this->doc->loadHTML($output);
			$this->xpath = new \DomXPath($this->doc);
			$this->xmlnsPrefix = '';
		}

		if(libxml_get_last_error() !== false) {
			$errors = [];
			$maxError = LIBXML_ERR_NONE;
			foreach(libxml_get_errors() as $error) {
				$errors[] = sprintf('[%s #%d] Line %d: %s', $error->level == LIBXML_ERR_WARNING ? 'Warning' : ($error->level == LIBXML_ERR_ERROR ? 'Error' : 'Fatal'), $error->code, $error->line, $error->message);
				$maxError = max($maxError, $error->level);
			}
			libxml_clear_errors();
			libxml_use_internal_errors($luie);
			$emsg = sprintf(
				"Form Population Filter encountered the following error%s while parsing the document:\n\n"
				. "%s\n\n"
				. "Non-fatal errors are typically recoverable; you may set the 'ignore_parse_errors' configuration parameter to LIBXML_ERR_WARNING or LIBXML_ERR_ERROR (default) to suppress them.\n"
				. "If you set 'ignore_parse_errors' to LIBXML_ERR_FATAL (recommended for production), Form Population Filter will silently abort execution in the event of fatal errors.\n"
				. "Regardless of the setting, all errors encountered will be logged.",
				count($errors) > 1 ? 's' : '',
				implode("\n", $errors)
			);
			if(AgaviConfig::get('core.use_logging') && $cfg['log_parse_errors'] !== false && $maxError >= $cfg['log_parse_errors']) {
				$severity = AgaviLogger::INFO;
				switch($maxError) {
					case LIBXML_ERR_WARNING:
						$severity = AgaviLogger::WARN;
						break;
					case LIBXML_ERR_ERROR:
						$severity = AgaviLogger::ERROR;
						break;
					case LIBXML_ERR_FATAL:
						$severity = AgaviLogger::FATAL;
						break;
				}
				$lmsg = $emsg . "\n\nResponse content:\n\n" . $response->getContent();
				$lm = $this->context->getLoggerManager();
				$mc = $lm->getDefaultMessageClass();
				$m = new $mc($lmsg, $severity);
				$lm->log($m, $cfg['logging_logger']);
			}
			
			// should we throw an exception, or carry on?
			if($maxError > $cfg['ignore_parse_errors']) {
				throw new AgaviParseException($emsg);
			} elseif($maxError == LIBXML_ERR_FATAL) {
				// for fatal errors, we cannot continue populating, so we must silently abort
				return;
			}
		}

		libxml_clear_errors();
		libxml_use_internal_errors($luie);

		$properXhtml = false;
		/** @var \DOMElement $meta */
		foreach($this->xpath->query(sprintf('//%1$shead/%1$smeta', $this->xmlnsPrefix)) as $meta) {
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

		if(($encoding = $cfg['force_encoding']) === false) {
			if($this->doc->encoding) { // doc->actualEncoding is deprecated in PHP 8.4
				$encoding = $this->doc->encoding;
			} else {
				$encoding = $this->doc->encoding = self::ENCODING_UTF_8;
			}
		} else {
			$this->doc->encoding = $encoding;
		}
		$encoding = strtolower((string) $encoding);
		$utf8 = $encoding == self::ENCODING_UTF_8;
		if(!$utf8 && $encoding != self::ENCODING_ISO_8859_1 && !function_exists('iconv')) {
			throw new AgaviException('No iconv module available, input encoding "' . $encoding . '" cannot be handled.');
		}

		/** @var \DOMNodeList<\DOMNode|\DOMNameSpaceNode>|false $base */
		$base = $this->xpath->query(sprintf('/%1$shtml/%1$shead/%1$sbase[@href]', $this->xmlnsPrefix));
		if($base->length) {
			/** @var \DOMElement $item */
			$item = $base->item(0);
			$baseHref = $item->getAttribute('href');
		} else {
			$baseHref = '';
		}
		$baseHref = substr((string) $baseHref, 0, strrpos((string) $baseHref, '/') + 1);

		$forms = [];
		if(is_array($populate)) {
			$queries = [];
			foreach($populate as $id => $data) {
				if(is_string($id)) {
					$id = sprintf('@id="%s"', $id);
					if($data === true) {
						// prepend to the array to give re-populates preferential treatment, see #1461
						array_unshift($queries, $id);
					} else {
						$queries[] = $id;
					}
				}
			}
			if($queries) {
				// we must assemble the array by hand as neither '//form[@id="foo"] or //form[@id="bar"]' nor '//form[@id="foo"] || //form[@id="bar"]' will order the elements as given in the query (order of element in the document is used instead and that can be a problem for error insertion, see #1461)
				$forms = [];
				foreach($queries as $query) {
					$form = $this->xpath->query(sprintf('//%1$sform[%2$s]', $this->xmlnsPrefix, $query));
					if($form->length) {
						$forms[] = $form->item(0);
					}
				}
			}
		} else {
			$forms = $this->xpath->query(AgaviToolkit::expandVariables($cfg['forms_xpath'], ['htmlnsPrefix' => $this->xmlnsPrefix]));
		}

		// an array of all validation incidents; errors inserted for fields or multiple fields will be removed in here
		$allIncidents = $vr->getIncidents();

		/** @var \DOMElement $form */
		foreach($forms as $form) {
			if($form->tagName == 'form') {
				if($populate instanceof AgaviParameterHolder) {
					$action = preg_replace('/#.*$/', '', trim((string) $form->getAttribute('action')));
					if(!(
						$action == $rurl ||
						(str_starts_with((string) $action, '/') && preg_replace(['#/\./#', '#/\.$#', '#[^\./]+/\.\.(/|\z)#', '#/{2,}#'], ['/', '/', '', '/'], (string) $action) == $ruri) ||
						$baseHref . preg_replace(['#/\./#', '#/\.$#', '#[^\./]+/\.\.(/|\z)#', '#/{2,}#'], ['/', '/', '', '/'], (string) $action) == $rurl
					)) {
						continue;
					}
					$p = $populate;
				} elseif(is_array($populate)) {
					$formId = $form->getAttribute('id');
					if($formId !== '' && isset($populate[$formId]) && $populate[$formId] instanceof AgaviParameterHolder) {
						$p = $populate[$formId];
					} else {
						continue;
					}
				} else {
					continue;
				}
			} else {
				if($populate instanceof AgaviParameterHolder) {
					$p = $populate;
				} elseif(is_array($populate)) {
					$p = $this->createParameterHolderFromRequest($request);
					if(!($p instanceof AgaviParameterHolder)) {
						continue;
					}
				} else {
					$p = $this->createParameterHolderFromRequest($request);
					if(!($p instanceof AgaviParameterHolder)) {
						continue;
					}
				}
			}

			// our array for remembering foo[] field's indices
			$remember = [];

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
			
			/** @var \DOMElement $element */
			foreach($this->xpath->query($query, $form) as $element) {

				$pname = $name = $element->getAttribute('name');

				$multiple = $element->nodeName == 'select' && $element->hasAttribute('multiple');

				$checkValue = false;
				if($element->getAttribute('type') == 'checkbox' || $element->getAttribute('type') == 'radio') {
					if(($pos = strpos((string) $pname, '[]')) && ($pos + 2 != strlen((string) $pname))) {
						// foo[][3] checkboxes etc not possible, [] must occur only once and at the end
						continue;
					} elseif($pos !== false) {
						$checkValue = true;
						$pname = substr((string) $pname, 0, $pos);
					}
				}
				if(preg_match_all('/([^\[]+)?(?:\[([^\]]*)\])/', (string) $pname, $matches)) {
					$pname = $matches[1][0];

					if($multiple) {
						$count = count($matches[2]) - 1;
					} else {
						$count = count($matches[2]);
					}
					for($i = 0; $i < $count; $i++) {
						$val = $matches[2][$i];
						if((string)$matches[2][$i] === (string)(int)$matches[2][$i]) {
							$val = (int)$val;
						}
						if(!isset($remember[$pname])) {
							$add = ($val !== "" ? $val : 0);
							if(is_int($add)) {
								$remember[$pname] = $add;
							}
						} else {
							if($val !== "") {
								$add = $val;
								if(is_int($val) && $add > $remember[$pname]) {
									$remember[$pname] = $add;
								}
							} else {
								$add = ++$remember[$pname];
							}
						}
						$pname .= '[' . $add . ']';
					}
				}

				if(!$utf8) {
					$pname = $this->fromUtf8($pname, $encoding);
				}

				if($skip !== null && preg_match($skip, $pname . ($checkValue ? '[]' : ''))) {
					// skip field
					continue;
				}

				$argument = new AgaviValidationArgument(
					$pname,
					($element->nodeName == 'input' && $element->getAttribute('type') == 'file')
						? "files" : "parameters"
				);
				
				// there's an error with the element's name in the request? good. let's give the baby a class!
				if($vr->getAuthoritativeArgumentSeverity($argument) > AgaviValidator::SILENT) {
					// a collection of all elements that need an error class
					$errorClassElements = [];
					// the element itself of course
					$errorClassElements[] = $element;
					// all implicit labels
					foreach($this->xpath->query(sprintf('ancestor::%1$slabel[not(@for)]', $this->xmlnsPrefix), $element) as $label) {
						$errorClassElements[] = $label;
					}
					// and all explicit labels
					if(($id = $element->getAttribute('id')) != '') {
						// we use // and not descendant: because it doesn't have to be a child of the form element
						foreach($this->xpath->query(sprintf('//%1$slabel[@for="%2$s"]', $this->xmlnsPrefix, $id), $form) as $label) {
							$errorClassElements[] = $label;
						}
					}

					// now loop over all those elements and assign the class
					foreach($errorClassElements as $errorClassElement) {
						// go over all the elements in the error class map
						foreach($cfg['error_class_map'] as $xpathExpression => $errorClassName) {
							// evaluate each xpath expression
							$errorClassResults = $this->xpath->query(AgaviToolkit::expandVariables($xpathExpression, ['htmlnsPrefix' => $this->xmlnsPrefix]), $errorClassElement);
							if($errorClassResults && $errorClassResults->length) {
								// we have results. the xpath expressions are used to locale the actual elements we set the error class on - doesn't necessarily have to be the erroneous element or the label!
								/** @var \DOMElement $errorClassDestinationElement */
								foreach($errorClassResults as $errorClassDestinationElement) {
									$errorClassDestinationElement->setAttribute('class', preg_replace('/\s*$/', ' ' . $errorClassName, $errorClassDestinationElement->getAttribute('class')));
								}
								
								// and break the foreach, our expression matched after all - no need to look further
								break;
							}
						}
					}

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
						$value = array_map(strval(...), $value);
					} else {
						$value = (string) $value;
					}
				}

				if($element->nodeName == 'input') {
					$inputType = $element->getAttribute('type');

					if($inputType == 'checkbox' || $inputType == 'radio') {

						// checkboxes and radios
						$element->removeAttribute('checked');

						if($checkValue && is_array($value)) {
							$eValue = $element->getAttribute('value');
							if(!$utf8) {
								$eValue = $this->fromUtf8($eValue, $encoding);
							}
							if(!in_array($eValue, $value)) {
								continue;
							} else {
								$element->setAttribute('checked', 'checked');
							}
						} elseif($p->hasParameter($pname) && (($element->hasAttribute('value') && $element->getAttribute('value') == $value) || (!$element->hasAttribute('value') && $p->getParameter($pname)))) {
							$element->setAttribute('checked', 'checked');
						}

					} elseif($inputType != 'button' && $inputType != 'submit') {
						
						// everything else
						
						// unless "include_hidden_inputs" is false and it's a hidden input...
						if($cfg['include_hidden_inputs'] || $inputType != 'hidden') {
							// remove original value
							$element->removeAttribute('value');
							
							// and set a new one if it's there and unless it's a password field (or we actually want to refill those)
							if($p->hasParameter($pname) && ($cfg['include_password_inputs'] || $inputType != 'password')) {
								$element->setAttribute('value', $value);
							}
						}
					}

				} elseif($element->nodeName == 'select') {
					// select elements
					// yes, we still use XPath because there could be OPTGROUPs
					/** @var \DOMElement $option */
					foreach($this->xpath->query(sprintf('descendant::%1$soption', $this->xmlnsPrefix), $element) as $option) {
						$option->removeAttribute('selected');
						if($p->hasParameter($pname) && ($option->getAttribute('value') === $value || ($multiple && is_array($value) && in_array($option->getAttribute('value'), $value)))) {
							$option->setAttribute('selected', 'selected');
						}
					}

				} elseif($element->nodeName == 'textarea') {

					// textareas
					foreach($element->childNodes as $cn) {
						// remove all child nodes (= text nodes)
						$element->removeChild($cn);
					}
					// append a new text node
					if($xhtml && $properXhtml) {
						$element->appendChild($this->doc->createCDATASection($value));
					} else {
						$element->appendChild($this->doc->createTextNode($value));
					}
				}

			}

			// now output the remaining incidents
			// might include errors for cookies, headers and whatnot, but that is okay
			if($this->insertErrorMessages($form, $errorMessageRules, $allIncidents)) {
				$allIncidents = [];
			}
		}

		FormPopulationConfig::setScopedValue($request, 'orphaned_errors', $allIncidents);

		if($xhtml) {
			$firstError = null;

			if(!$cfg['parse_xhtml_as_xml']) {
				// workaround for a bug in dom or something that results in two xmlns attributes being generated for the <html> element
				// attributes must be removed and created again
				// and don't change the DOMNodeList in the foreach!
				$remove = [];
				$reset = [];
				foreach($this->doc->documentElement->attributes as $attribute) {
					// remember to remove the node
					$remove[] = $attribute;
					// not for the xmlns attribute itself
					if($attribute->nodeName != 'xmlns') {
						// can't do $attribute->prefix. we're in HTML parsing mode, remember? even if there is a prefix, the attribute node will not have a namespace
						$attributeNameParts = explode(':', $attribute->nodeName);
						if(isset($attributeNameParts[1])) {
							// it's a namespaced node
							$attributeNamespaceUri = $attribute->parentNode->lookupNamespaceURI($attributeNameParts[0]);
							if($attributeNamespaceUri) {
								// it is an attribute, for which the namespace is known internally (even though we're in HTML mode), typically xml: or xmlns:.
								// so we need to create a new node, in the right namespace
								$attributeCopy = $this->doc->createAttributeNS($attributeNamespaceUri, $attribute->nodeName);
							} else {
								// it's a foo:bar node - just copy it over
								$attributeCopy = $attribute;
							}
						} else {
							// no namespace on this node, copy it
							$attributeCopy = $attribute;
						}
						// don't forget the attribute value
						$attributeCopy->nodeValue = $attribute->nodeValue;
						// and remember to set this attribute later
						$reset[] = $attributeCopy;
					}
				}
				
				foreach($remove as $attribute) {
					$this->doc->documentElement->removeAttributeNode($attribute);
				}
				foreach($reset as $attribute) {
					$this->doc->documentElement->setAttributeNode($attribute);
				}
			}
			$out = $this->doc->saveXML(null, $cfg['savexml_options']);
			if((!$cfg['parse_xhtml_as_xml'] || !$properXhtml) && $cfg['cdata_fix']) {
				// these are ugly fixes so inline style and script blocks still work. better don't use them with XHTML to avoid trouble
				// http://www.456bereastreet.com/archive/200501/the_perils_of_using_xhtml_properly/
				// http://www.hixie.ch/advocacy/xhtml
				$out = preg_replace('/<style([^>]*)>\s*<!\[CDATA\[\s*?/iU' . ($utf8 ? 'u' : ''), '<style$1><!--/*--><![CDATA[/*><!--*/' . "\n", $out);
				if(!$firstError) {
					$firstError = preg_last_error();
				}
				// we can't clean up whitespace before the closing element because a preg with a leading \s* expression would be horribly slow
				$out = preg_replace('/\]\]>\s*<\/style>/iU' . ($utf8 ? 'u' : ''), "\n" . '/*]]>*/--></style>', (string) $out);
				if(!$firstError) {
					$firstError = preg_last_error();
				}
				$out = preg_replace('/<script([^>]*)>\s*<!\[CDATA\[\s*?/iU' . ($utf8 ? 'u' : ''), '<script$1><!--//--><![CDATA[//><!--' . "\n", (string) $out);
				if(!$firstError) {
					$firstError = preg_last_error();
				}
				// we can't clean up whitespace before the closing element because a preg with a leading \s* expression would be horribly slow
				$out = preg_replace('/\]\]>\s*<\/script>/iU' . ($utf8 ? 'u' : ''), "\n" . '//--><!]]></script>', (string) $out);
				if(!$firstError) {
					$firstError = preg_last_error();
				}
			}
			if($cfg['remove_auto_xml_prolog'] && !$hasXmlProlog) {
				// there was no xml prolog in the document before, so we remove the one generated by DOM now
				$out = preg_replace('/<\?xml.*?\?>\s+/iU' . ($utf8 ? 'u' : ''), '', $out);
				if(!$firstError) {
					$firstError = preg_last_error();
				}
			} elseif(!$cfg['parse_xhtml_as_xml']) {
				// yes, DOM sucks and inserts another XML prolog _after_ the DOCTYPE... and it has two question marks at the end, not one, don't ask me why
				$out = preg_replace('/<\?xml.*?\?\?>\s+/iU' . ($utf8 ? 'u' : ''), '', $out);
				if(!$firstError) {
					$firstError = preg_last_error();
				}
			}
			
			if($firstError) {
				$error = "Form Population Filter encountered an error while performing final regular expression replaces on the output.\n";
				// the preg_replaces failed and produced an empty string. let's find out why
				$error .= "The error reported by preg_last_error() indicates that ";
				match ($firstError) {
                    PREG_BAD_UTF8_ERROR => $error .= "the input contained malformed UTF-8 data.",
                    PREG_RECURSION_LIMIT_ERROR => $error .= "the recursion limit (defined by \"pcre.recursion_limit\") was hit. This shouldn't happen unless you changed that limit yourself in php.ini or using ini_set(). If the problem is not on your end, please file a bug report with a reproduce case on the Agavi issue tracker or drop by on the IRC support channel.",
                    PREG_BACKTRACK_LIMIT_ERROR => $error .= "the backtrack limit (defined by \"pcre.backtrack_limit\") was hit. This shouldn't happen unless you changed that limit yourself in php.ini or using ini_set(). If the problem is not on your end, please file a bug report with a reproduce case on the Agavi issue tracker or drop by on the IRC support channel.",
                    default => $error .= "an internal PCRE error occurred. As a quick countermeasure, try to upgrade PHP (and the bundled PCRE) as well as libxml (yes!) to the latest versions to see if the problem goes away. If the issue persists, file a bug report with a reproduce case on the Agavi issue tracker or drop by on the IRC support channel.",
                };
				throw new AgaviException($error);
			}

			$response->setContent($out);
		} else {
			$response->setContent($this->doc->saveHTML());
		}
		unset($this->xpath);
		unset($this->doc);
	}

	public function isPostFilter(): bool
	{
		return true;
	}

	/**
	 * Insert the error messages from the given incidents into the given element
	 * using the given rules.
	 *
	 * @param      \DOMElement The element to work on.
	 * @param      array      An array of insertion rules
	 * @param      array      An array of AgaviValidationIncidents.
	 *
	 * @return     bool Whether or not the inserts were successful.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function insertErrorMessages(\DOMElement $element, array $rules, array $incidents)
	{
		$errors = [];
		foreach($incidents as $incident) {
			if($incident->getSeverity() <= AgaviValidator::SILENT) {
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

		$luie = libxml_use_internal_errors(true);
		libxml_clear_errors();

		$insertSuccessful = false;
		foreach($rules as $xpathExpression => $errorMessageInfo) {
			$targets = $this->xpath->query(AgaviToolkit::expandVariables($xpathExpression, ['htmlnsPrefix' => $this->xmlnsPrefix]), $element);

			if(!$targets || !$targets->length) {
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
				throw new AgaviException('Form Population Filter was unable to insert error messages into the document using the XPath expression "' . $xpathExpression . '" because the element information did not contain either a "markup" or "container" entry to use.');
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
							$errorElement = $this->doc->createDocumentFragment();
							$errorElement->appendXML($errorElementHtml);
						} else {
							$this->doc->importNode($errorElement, true);
						}
					} elseif(is_string($errorMarkup)) {
						// it's a string with the HTML to insert
						// %s is the placeholder in the HTML for the error message
						$errorElement = $this->doc->createDocumentFragment();
						$errorElement->appendXML(
							AgaviToolkit::expandVariables(
								$errorMarkup,
								[
									'elementId'    => htmlspecialchars($element->getAttribute('id'), ENT_QUOTES, 'UTF-8'),
									'elementName'  => htmlspecialchars($element->getAttribute('name'), ENT_QUOTES, 'UTF-8'),
									'errorMessage' => htmlspecialchars((string) $error->getMessage(), ENT_QUOTES, 'UTF-8'),
								]
							)
						);
					} else {
						throw new AgaviException('Form Population Filter was unable to insert an error message into the document using the XPath expression "' . $xpathExpression . '" because the element information could not be evaluated as an XML/HTML fragment or as a PHP callback.');
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
						$containerElement = $this->doc->createDocumentFragment();
						$containerElement->appendXML($containerElementHtml);
					} else {
						$this->doc->importNode($containerElement, true);
					}
				} elseif(is_string($errorContainer)) {
					// it's a string with the HTML to insert
					// %s is the placeholder in the HTML for the error message
					$containerElement = $this->doc->createDocumentFragment();
					$containerElement->appendXML(
						AgaviToolkit::expandVariables(
							$errorContainer,
							[
								'elementId'     => htmlspecialchars($element->getAttribute('id'), ENT_QUOTES, 'UTF-8'),
								'elementName'   => htmlspecialchars($element->getAttribute('name'), ENT_QUOTES, 'UTF-8'),
								'errorMessages' => implode("\n", $errorStrings),
							]
						)
					);
				} else {
					throw new AgaviException('Form Population Filter was unable to insert an error message container into the document using the XPath expression "' . $xpathExpression . '" because the element information could not be evaluated as an XML/HTML fragment or as a PHP callback.');
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
				throw new AgaviParseException($emsg);
			}

			foreach($errorElements as $errorElement) {
				foreach($targets as $target) {
					// in case the target yielded more than one location, we need to clone the element
					// because the document fragment node will be corrupted after an insert
					$clonedErrorElement = $errorElement->cloneNode(true);
					
					if($errorLocation == 'before') {
						$target->parentNode->insertBefore($clonedErrorElement, $target);
					} elseif($errorLocation == 'after') {
						// check if there is a following sibling, then insert before that one
						// if not, append to parent
						if($target->nextSibling) {
							$target->parentNode->insertBefore($clonedErrorElement, $target->nextSibling);
						} else {
							$target->parentNode->appendChild($clonedErrorElement);
						}
					} elseif($errorLocation == 'replace') {
						$target->parentNode->replaceChild($clonedErrorElement, $target);
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
	 *
	 * @param      mixed  The value to convert (can be an array).
	 * @param      string The encoding of the value.
	 *
	 * @return     mixed  The converted value.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function toUtf8($value, $encoding = self::ENCODING_ISO_8859_1)
	{
		if($encoding == self::ENCODING_ISO_8859_1) {
			if(is_array($value)) {
				foreach($value as &$val) {
					$val = $this->toUtf8($val, $encoding);
				}
			} else {
				$value = mb_convert_encoding((string) $value, 'UTF-8', 'ISO-8859-1');
			}
		} else {
			if(is_array($value)) {
				foreach($value as &$val) {
					$val = $this->toUtf8($val, $encoding);
				}
			} else {
				$value = iconv((string) $encoding, self::ENCODING_UTF_8, (string) $value);
			}
		}

		return $value;
	}

	/**
	 * Decode given value from UTF-8
	 *
	 * @param      mixed  The value to convert (can be an array).
	 * @param      string The encoding of the value.
	 *
	 * @return     mixed  The converted value.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	protected function fromUtf8($value, $encoding = self::ENCODING_ISO_8859_1)
	{
		if($encoding == self::ENCODING_ISO_8859_1) {
			if(is_array($value)) {
				foreach($value as &$val) {
					$val = $this->fromUtf8($val, $encoding);
				}
			} else {
				$value = mb_convert_encoding((string) $value, 'ISO-8859-1');
			}
		} else {
			if(is_array($value)) {
				foreach($value as &$val) {
					$val = $this->fromUtf8($val, $encoding);
				}
			} else {
				$value = iconv(self::ENCODING_UTF_8, (string) $encoding, (string) $value);
			}
		}

		return $value;
	}

	/**
	 * Initialize this filter.
	 *
	 * @param      AgaviContext The current application context.
	 * @param      array        An associative array of initialization parameters.
	 *
	 * @throws     <b>AgaviFilterException</b> If an error occurs during
	 *                                         initialization
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
    public function initialize(AgaviContext $context, array $parameters = []): void
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
	private function ensureSeedInitialized(AgaviWebRequest $request): void
	{
		// Always seed - the seed() method itself is idempotent and won't overwrite
		// existing values, only fill in defaults for missing keys
		FormPopulationConfig::seed($request, $this->parameters);
	}

	public function reset(): void
	{
		$this->doc = null;
		$this->xpath = null;
		$this->xmlnsPrefix = '';
	}

	public function getDefaults(): array
	{
		return $this->parameters;
	}

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
				$savexmlOptions |= constant($option);
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

	private function buildConfiguration(AgaviWebRequest $request, array $overrides): array
	{
		$config = array_replace($this->parameters, FormPopulationConfig::get($request));
		if($overrides) {
			$config = array_replace($config, $overrides);
		}
		return $this->normalizeParameters($config);
	}

	/**
	 * Resolve populate configuration into parameter holders understood by the processor.
	 */
	protected function resolvePopulateSource($request, array $cfg)
	{
		$populateConfig = $cfg['populate'] ?? null;
		if(is_array($populateConfig)) {
			$result = [];
			foreach($populateConfig as $key => $value) {
				$holder = null;
				if($value instanceof AgaviParameterHolder) {
					$holder = $value;
				} elseif($value === true) {
					$holder = $this->createParameterHolderFromRequest($request);
				} elseif(is_array($value)) {
					$holder = new AgaviParameterHolder($value);
				}
				if($holder instanceof AgaviParameterHolder && is_string($key) && $key !== '') {
					$result[$key] = $holder;
				}
			}
			return $result;
		}

		if($populateConfig instanceof AgaviParameterHolder) {
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
			try {
				$requestMethod = strtoupper((string) $request->getMethod());
			} catch(\Throwable) {
			}
		}
		$methodAllowed = $allowAllMethods ? true : ($requestMethod !== null && in_array($requestMethod, $methods, true));

		if($populateConfig === true || ($methodAllowed && $populateConfig !== false)) {
			$holder = $this->createParameterHolderFromRequest($request);
			if($holder instanceof AgaviParameterHolder) {
				return $holder;
			}
		}

		return null;
	}

	/**
	 * Create a parameter holder from the given request-like object.
	 */
	protected function createParameterHolderFromRequest($request): ?AgaviParameterHolder
	{
		if($request instanceof AgaviParameterHolder) {
			return $request;
		}
		if($request instanceof AgaviWebRequest) {
			try {
				$params = $request->getParameters();
			} catch(\Throwable) {
				$params = [];
			}
			if($params instanceof AgaviParameterHolder) {
				return $params;
			}
			if(is_array($params)) {
				return new AgaviParameterHolder($params);
			}
		}
		if(is_object($request) && method_exists($request, 'getParameters')) {
			try {
				$params = $request->getParameters();
			} catch(\Throwable) {
				$params = null;
			}
			if($params instanceof AgaviParameterHolder) {
				return $params;
			}
			if(is_array($params)) {
				return new AgaviParameterHolder($params);
			}
		}
		if(class_exists('Agavi\\Request\\AgaviWebRequestDataHolder') && is_a($request, 'Agavi\\Request\\AgaviWebRequestDataHolder')) {
			$params = $request->getParameters();
			if($params instanceof AgaviParameterHolder) {
				return $params;
			}
			if(is_array($params)) {
				return new AgaviParameterHolder($params);
			}
		}
		return null;
	}

	protected function resolveRequestUri($request): string
	{
		if($request instanceof AgaviWebRequest) {
			try {
				$uri = (string) $request->getRequestUri();
				if($uri !== '') {
					return $uri;
				}
			} catch(\Throwable) {
			}
			try {
				$path = (string) $request->getUrlPath();
				if($path !== '') {
					return $path;
				}
			} catch(\Throwable) {
			}
		}
		if(is_object($request) && method_exists($request, 'getAttribute')) {
			try {
				$attr = $request->getAttribute('request_uri');
				if(is_string($attr) && $attr !== '') {
					return $attr;
				}
			} catch(\Throwable) {
			}
		}
		return '/';
	}

	protected function resolveRequestUrl($request, string $fallbackUri): string
	{
		if($request instanceof AgaviWebRequest) {
			try {
				$url = (string) $request->getUrl();
				if($url !== '') {
					return $url;
				}
			} catch(\Throwable) {
			}
		}
		if(is_object($request) && method_exists($request, 'getAttribute')) {
			try {
				$attr = $request->getAttribute('request_url');
				if(is_string($attr) && $attr !== '') {
					return $attr;
				}
			} catch(\Throwable) {
			}
		}
		return $fallbackUri;
	}
}

?>
