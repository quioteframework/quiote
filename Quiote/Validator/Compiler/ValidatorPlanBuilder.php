<?php
namespace Quiote\Validator\Compiler;

use Quiote\Config\Config;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Exception\ConfigurationException;
use Quiote\Logging\Log;
use Quiote\Support\Compiler\Diagnostic;
use Quiote\Util\Toolkit;
use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;
use Quiote\Validator\Validator;

/**
 * Walks a parsed validators.xml document and builds a format-independent
 * ValidatorPlan (see Quiote\Validator\Compiler\Ir). This is the traversal
 * that used to live inline in ValidatorConfigHandler, interleaved with PHP
 * code emission; it has been split out so the same walk can feed multiple
 * back-ends (the runtime cache emitter, a fluent-source emitter for a
 * future compiler CLI) without duplicating XML-interpretation logic.
 *
 * A ValidatorPlanBuilder instance is single-use: construct one per
 * document, call build() once. classMap accumulates across the
 * <ae:configuration> elements of that one document, matching the
 * historical per-file scoping of validator_definitions.
 * @since      1.0.0
 */
class ValidatorPlanBuilder
{
	/**
	 * Controls how unknown/misspelled validator parameters are handled at
	 * plan-build (i.e. config-compile) time. See checkParameters().
	 */
	const REJECT_MODE_THROW = 'throw';
	const REJECT_MODE_WARN = 'warn';
	const REJECT_MODE_OFF = 'off';

	/**
	 * name => [class, parameters, errors]
	 * (definition name, not necessarily the real PHP class)
	 * @var array<string, array{class: string, parameters: array<int|string, mixed>, errors: array<mixed>}>
	 */
	protected array $classMap = [];

	/** @var Diagnostic[] */
	protected array $diagnostics = [];

	protected ?string $sourceRef = null;

	protected string $namespace;

	public function build(XmlConfigDomDocument $document, string $namespace): ValidatorPlan
	{
		$this->namespace = $namespace;
		$document->setDefaultNamespace($namespace, 'validators');
		$this->sourceRef = (string) $document->documentURI;

		$nodes = [];
		foreach ($document->getConfigurationElements() as $cfg) {
			if ($cfg->has('validator_definitions')) {
				// get() only ever selects element nodes, and registerNodeClass()
				// guarantees those are always XmlConfigDomElement, never a vanilla DOMNode.
				/** @var iterable<int, XmlConfigDomElement> $definitionNodes */
				$definitionNodes = $cfg->get('validator_definitions');
				foreach ($definitionNodes as $def) {
					$name = $this->requireAttribute($def, 'name', 'A <validator_definition>');
					if (!isset($this->classMap[$name])) {
						$this->classMap[$name] = [
							'class' => $this->requireAttribute($def, 'class', sprintf('validator_definition "%s"', $name)),
							'parameters' => [],
							'errors' => [],
						];
					}
					// merge / override
					$this->classMap[$name]['class'] = $def->getAttribute('class', $this->classMap[$name]['class']) ?? $this->classMap[$name]['class'];
					$this->classMap[$name]['parameters'] = $def->getQuioteParameters($this->classMap[$name]['parameters']);
					$this->classMap[$name]['errors'] = $this->collectErrors($def, $this->classMap[$name]['errors']);
				}
			}
			$nodes = array_merge($nodes, $this->buildValidatorElements($cfg, 'error', null, true, null));
		}

		return new ValidatorPlan($nodes, $this->sourceRef);
	}

	/**
	 * @return Diagnostic[] Every diagnostic recorded during the last build().
	 *                       Populated in 'warn' mode; in 'throw' mode only
	 *                       diagnostics from nodes visited before the fatal
	 *                       one are ever recorded, since the exception
	 *                       aborts the walk immediately.
	 */
	public function getDiagnostics(): array
	{
		return $this->diagnostics;
	}

	/**
	 * @return ValidatorNode[]
	 */
	protected function buildValidatorElements(XmlConfigDomElement $node, string $defaultSeverity, ?string $defaultMethod, bool $defaultRequired, ?string $defaultTranslationDomain): array
	{
		$nodes = [];
		foreach ($node->get('validators') as $validator) {
			/** @var XmlConfigDomElement $parentNode */
			$parentNode = $validator->parentNode;
			if ($parentNode->localName == 'validators') {
				$severity = $parentNode->getAttribute('severity', $defaultSeverity) ?? $defaultSeverity;
				$method = $parentNode->getAttribute('method', $defaultMethod);
				$translationDomain = $parentNode->getAttribute('translation_domain', $defaultTranslationDomain);
			} else {
				$severity = $defaultSeverity;
				$method = $defaultMethod;
				$translationDomain = $defaultTranslationDomain;
			}
			$required = $defaultRequired;
			$nodes[] = $this->buildValidatorNode($validator, $severity, $method, $required, $translationDomain);
		}
		return $nodes;
	}

	/**
	 * @param XmlConfigDomElement $validator
	 */
	protected function buildValidatorNode(XmlConfigDomElement $validator, string $stdSeverity, ?string $stdMethod, bool $stdRequired, ?string $stdTranslationDomain): ValidatorNode
	{
		[$class, $defParams, $defErrors] = $this->resolveClass($this->requireAttribute($validator, 'class', 'A <validator>'));

		$parameters = array_merge($defParams, [
			'severity' => $validator->getAttribute('severity', $stdSeverity),
			'required' => $stdRequired,
		], $validator->getAttributes());

		$parameters = $validator->getQuioteParameters($parameters);
		if (!array_key_exists('translation_domain', $parameters) && $stdTranslationDomain !== null) {
			$parameters['translation_domain'] = $stdTranslationDomain;
		} elseif (($parameters['translation_domain'] ?? '') === '') {
			unset($parameters['translation_domain']);
		}

		$arguments = [];
		foreach ($validator->get('arguments') as $argument) {
			// registerNodeClass() guarantees every node here is a XmlConfigDomElement,
			// never a vanilla DOMNode.
			/** @var XmlConfigDomElement $argument */
			if ($argument->hasAttribute('name')) {
				$arguments[$argument->getAttribute('name') ?? ''] = $argument->getValue();
			} else {
				$arguments[] = $argument->getValue();
			}
		}
		$argumentsChild = $validator->getChild('arguments');
		if ($argumentsChild !== null) {
			$parameters['base'] = $argumentsChild->getAttribute('base');
			if (!$arguments) { $arguments[] = ''; }
		}

		$errors = $this->collectErrors($validator, $defErrors);
		if ($validator->hasAttribute('required')) {
			$stdRequired = $parameters['required'] = Toolkit::literalize($validator->getAttribute('required'));
		}

		$stdMethod = $validator->getAttribute('method', $stdMethod);
		$methods = [''];
		if (trim((string)$stdMethod)) {
			$splitMethods = preg_split('/[\s]+/', (string)$stdMethod);
			$methods = $splitMethods !== false ? $splitMethods : [''];
		}

		$name = $validator->getAttribute('name');
		if ($name === null || $name === '') {
			$name = Toolkit::uniqid();
			$validator->setAttribute('name', $name);
		}

		// Compute the request parameter names this validator operates on so
		// they can be seeded into the request's strict-validation whitelist.
		$declaredNames = $this->computeDeclaredNamesForValidator(
			$arguments,
			(string)($parameters['base'] ?? '')
		);

		// A validator's <ae:parameter name="export">X</ae:parameter> tells the
		// runtime that the validator may publish a request parameter named X.
		// Whitelist it unconditionally — it's a legitimate output of this
		// action's validation pipeline, whether or not the export actually
		// fires in a given request.
		if (array_key_exists('export', $parameters) && is_string($parameters['export']) && $parameters['export'] !== '') {
			foreach ($this->expandExportName((string)$parameters['export']) as $exported) {
				$declaredNames[] = $exported;
			}
		}

		$this->checkParameters($class, $parameters, $validator);

		$base = (string)($parameters['base'] ?? '');

		$children = $this->buildValidatorElements(
			$validator,
			$parameters['severity'],
			$stdMethod,
			$stdRequired,
			$parameters['translation_domain'] ?? null
		);

		return new ValidatorNode(
			$name,
			$class,
			$arguments,
			$base,
			$parameters,
			$errors,
			$methods,
			$declaredNames,
			$children
		);
	}

	/**
	 * Reads a required XML attribute, failing loudly with a diagnosable
	 * ConfigurationException rather than letting a missing/empty attribute
	 * silently key an array by "" or reach a constructor as null where a
	 * real value is required (e.g. a validator without a resolvable class).
	 */
	private function requireAttribute(XmlConfigDomElement $node, string $attribute, string $context): string
	{
		$value = $node->getAttribute($attribute);
		if ($value === null || $value === '') {
			throw new ConfigurationException(sprintf(
				'%s is missing its required "%s" attribute in %s.',
				$context,
				$attribute,
				$this->sourceRef ?? '?'
			));
		}
		return $value;
	}

	/**
	 * @return array{0: string, 1: array<int|string, mixed>, 2: array<mixed>}
	 */
	protected function resolveClass(string $declared): array
	{
		// If declared token is a definition name, return mapped class + defaults
		if (isset($this->classMap[$declared])) {
			$def = $this->classMap[$declared];
			return [
				$def['class'],
				$def['parameters'],
				$def['errors']
			];
		}
		// Not defined: treat as direct class (backwards-compatible).
		// If $declared is a short name (no backslash), the compiled config will emit
		// "new ShortName()" which requires the class to be autoloadable under that
		// exact token. If it's not, add a <validator_definition> mapping in validators.xml.
		return [$declared, [], []];
	}

	/**
	 * @param XmlConfigDomElement $node
	 * @param array<string, mixed> $existing
	 * @return array<string, mixed>
	 */
	public function collectErrors(XmlConfigDomElement $node, array $existing = []): array
	{
		$result = $existing;
		$elements = $node->get('errors', $this->namespace);
		foreach ($elements as $element) {
			// registerNodeClass() guarantees every node here is a XmlConfigDomElement,
			// never a vanilla DOMNode.
			/** @var XmlConfigDomElement $element */
			// New simplified semantics:
			// <error>foo</error>            => ['' => 'foo']
			// <error for="min">bar</error> => ['min' => 'bar']
			// <error name=...> (namespaced multi-domain form) => legacy structured branch
			if ($element->hasAttribute('name')) {
				$name = $element->getAttribute('name') ?? '';
				$domains = [];
				foreach ($element->get('domain') as $domainElement) {
					/** @var XmlConfigDomElement $domainElement */
					$domains[$domainElement->getAttribute('name') ?? ''] = $domainElement->getValue();
				}
				$result[$name] = [
					'parameters' => $element->getQuioteParameters(isset($result[$name]) ? $result[$name]['parameters'] : []),
					'domains' => $domains,
				];
				continue;
			}
			$val = $element->getValue();
			if ($val === '') { continue; }
			if ($element->hasAttribute('for')) {
				$result[$element->getAttribute('for') ?? ''] = $val;
			} else {
				$result[''] = $val;
			}
		}
		return $result;
	}

	/**
	 * Expand an export name specifier into whitelist entries.
	 * Plain names (e.g. "bulletin") are whitelisted as-is. Bracketed names
	 * (e.g. "responsibleUsers[%2$s]" or "Tag[%2$s]") have the root extracted
	 * — the request's bracket-alias machinery matches child lookups against
	 * the whitelisted root.
	 * @return string[] One or more whitelist entries.
	 */
	private function expandExportName(string $exportName): array
	{
		$exportName = trim($exportName);
		if ($exportName === '') {
			return [];
		}
		$bracketAt = strpos($exportName, '[');
		if ($bracketAt === false) {
			return [$exportName];
		}
		$root = substr($exportName, 0, $bracketAt);
		if ($root === '') {
			return [];
		}
		return [$root];
	}

	/**
	 * Collect the effective request parameter names a validator reads.
	 * Honors <arguments base="..."> by prepending the base path.
	 * @param array<int|string, mixed> $arguments Flat list of argument values (the request
	 *                         parameter name or sub-path the validator reads).
	 * @param string $base Optional base path from <arguments base="...">.
	 * @return string[] Effective parameter names to whitelist.
	 */
	private function computeDeclaredNamesForValidator(array $arguments, string $base): array
	{
		$names = [];
		if ($base === '') {
			foreach ($arguments as $arg) {
				if (is_string($arg) && $arg !== '') {
					$names[] = $arg;
				}
			}
			return $names;
		}
		// Base present. Whitelist the full base path (e.g. "UserReference[][]")
		// AND the bare root name (e.g. "UserReference") so that action code
		// reading `getParameter('UserReference')` for the whole array is also
		// allowed under strict validation.
		$names[] = $base;
		$bracketPos = strpos($base, '[');
		if ($bracketPos !== false) {
			$bareRoot = substr($base, 0, $bracketPos);
			if ($bareRoot !== '') {
				$names[] = $bareRoot;
			}
		}
		foreach ($arguments as $arg) {
			if (!is_string($arg)) { continue; }
			if ($arg === '') {
				// Validator targets the base directly.
				continue;
			}
			$names[] = $base . '[' . $arg . ']';
		}
		return $names;
	}

	/**
	 * Rejects validator parameters (XML attributes and <ae:parameter>
	 * entries) that the resolved validator class does not declare in
	 * Validator::getAcceptedParameters(). This runs at plan-build (i.e.
	 * config-compile) time, not per request, so it costs nothing at
	 * runtime.
	 *
	 * A validator config that silently ignores an unknown attribute is
	 * exactly the shape of bug that let a `values="a,b,c"` allowlist
	 * attribute on a validator that never read it slip through unenforced
	 * and reach an action unvalidated. Failing the build closed here turns
	 * that into a compile error instead of a silent no-op.
	 *
	 * Controlled by the `validation.reject_unknown_parameters` config key:
	 * 'throw' (default) fails the build, 'warn' logs, records a Diagnostic,
	 * and continues (useful for auditing an existing corpus before flipping
	 * to 'throw'), 'off' skips the check entirely.
	 */
	/**
	 * @param array<int|string, mixed> $parameters
	 * @param XmlConfigDomElement $validator
	 */
	protected function checkParameters(string $class, array $parameters, XmlConfigDomElement $validator): void
	{
		$mode = Config::getString('validation.reject_unknown_parameters', self::REJECT_MODE_THROW);
		if ($mode === self::REJECT_MODE_OFF) {
			return;
		}

		if (!class_exists($class) || !is_subclass_of($class, Validator::class)) {
			// Can't introspect (custom/late-bound class not yet autoloadable
			// at compile time) -- say so rather than silently pretending the
			// parameters were checked.
			$message = sprintf(
				'cannot introspect "%s" in %s; parameter names unchecked',
				$class,
				$this->sourceRef ?? '?'
			);
			Log::for($this)->notice('[validators] ' . $message);
			$this->diagnostics[] = new Diagnostic(
				Diagnostic::SEVERITY_WARNING,
				Diagnostic::CODE_UNRESOLVABLE_CLASS,
				$message,
				$this->sourceRef ?? '?'
			);
			return;
		}

		/** @var class-string<Validator> $class */
		$accepted = $class::getAcceptedParameters();
		$acceptedSet = array_fill_keys($accepted, true);

		foreach (array_keys($parameters) as $key) {
			if (!is_string($key) || isset($acceptedSet[$key])) {
				continue;
			}

			$hint = $this->suggestParameterName($key, $accepted);
			$message = sprintf(
				'Unknown parameter "%s" on validator "%s" (%s) in %s.%s Accepted: %s.',
				$key,
				$validator->getAttribute('name', $validator->getAttribute('class', $class)),
				$class,
				$this->sourceRef ?? '?',
				$hint !== null ? ' Did you mean "' . $hint . '"?' : '',
				implode(', ', $accepted)
			);

			if ($mode === self::REJECT_MODE_WARN) {
				Log::for($this)->warning('[validators] ' . $message);
				$this->diagnostics[] = new Diagnostic(
					Diagnostic::SEVERITY_WARNING,
					Diagnostic::CODE_UNKNOWN_PARAMETER,
					$message,
					$this->sourceRef ?? '?'
				);
				continue;
			}

			throw new ConfigurationException($message);
		}
	}

	/**
	 * Finds the closest accepted parameter name to an unknown one, to turn
	 * a compile error into an actionable typo hint. Only suggests when the
	 * edit distance is small enough to plausibly be a typo rather than a
	 * genuinely different (nonexistent) feature.
	 * @param string[] $accepted
	 */
	private function suggestParameterName(string $unknown, array $accepted): ?string
	{
		$best = null;
		$bestDistance = PHP_INT_MAX;
		foreach ($accepted as $name) {
			$distance = levenshtein($unknown, $name);
			if ($distance < $bestDistance) {
				$bestDistance = $distance;
				$best = $name;
			}
		}
		return $bestDistance <= 3 ? $best : null;
	}
}
