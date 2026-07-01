<?php
// Minimal, instrumentation-free validator config handler (restored simplified semantics)
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Util\Toolkit;

class ValidatorConfigHandler extends XmlConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/validators/1.1';

	/**
	 * name => [class, parameters, errors]
	 * (definition name, not necessarily the real PHP class)
	 */
	protected array $classMap = [];

	/**
	 * Map of HTTP method => list of request parameter names declared by
	 * validators in the current config. Populated during processing and
	 * consumed in execute() to emit a declareParameters() seed call into
	 * the compiled artifact, so the request's strict-validation whitelist
	 * is populated before any validator runs (and remains valid in error
	 * views where validation aborts).
	 * The empty-string key holds methodless (always-on) declarations.
	 */
	protected array $declaredParams = [];

	public function execute(XmlConfigDomDocument $document): string
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'validators');
		$config = $document->documentURI;
		$code = [];
		$this->declaredParams = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			if ($cfg->has('validator_definitions')) {
				foreach ($cfg->get('validator_definitions') as $def) {
					$name = $def->getAttribute('name');
					if (!isset($this->classMap[$name])) {
						$this->classMap[$name] = [
							'class' => $def->getAttribute('class'),
							'parameters' => [],
							'errors' => [],
						];
					}
					// merge / override
					$this->classMap[$name]['class'] = $def->getAttribute('class', $this->classMap[$name]['class']);
					$this->classMap[$name]['parameters'] = $def->getQuioteParameters($this->classMap[$name]['parameters']);
					$this->classMap[$name]['errors'] = $this->getQuioteErrors($def, $this->classMap[$name]['errors']);
				}
			}
			$code = $this->processValidatorElements($cfg, $code, 'validationManager');
		}

		$final = [];

		// Emit unconditional whitelist seed: declarations that apply regardless
		// of request method. Runs before any conditional method block so the
		// whitelist is populated whether or not a method branch matches.
		$unconditionalNames = $this->uniqueDeclaredNames('');
		if (!empty($unconditionalNames)) {
			$final[] = $this->buildDeclareParametersSnippet($unconditionalNames);
		}

		if (isset($code[''])) {
			foreach ($code[''] as $snippet) { $final[] = $snippet; }
			unset($code['']);
		}
		foreach ($code as $method => $snippets) {
			$final[] = 'if($method == ' . var_export($method, true) . '){';
			// Per-method whitelist seed. Emitted inside the conditional so
			// that params declared only for (e.g.) 'write' aren't whitelisted
			// on a 'read' request.
			$methodNames = $this->uniqueDeclaredNames($method);
			if (!empty($methodNames)) {
				$final[] = $this->buildDeclareParametersSnippet($methodNames);
			}
			foreach ($snippets as $snippet) { $final[] = $snippet; }
			$final[] = '}';
		}
		return $this->generate($final, $config);
	}

	/**
	 * Return the deduped list of parameter names declared for a given
	 * method key. The empty string represents methodless declarations.
	 */
	private function uniqueDeclaredNames(string $method): array
	{
		$names = $this->declaredParams[$method] ?? [];
		if (empty($names)) {
			return [];
		}
		// array_values(array_unique(...)) gives a clean, deterministic order
		$unique = array_values(array_unique($names));
		sort($unique);
		return $unique;
	}

	/**
	 * Build the generated PHP line that declares a batch of parameter names
	 * on the request's strict-validation whitelist.
	 */
	private function buildDeclareParametersSnippet(array $names): string
	{
		return sprintf(
			'$validationManager->getContext()->getRequest()->declareParameters(%s);',
			var_export(array_values($names), true)
		);
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
	 * @param array $arguments Flat list of argument values (the request
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

	protected function getValidatorArray(XmlConfigDomElement $validator, array $code, string $parent = 'validationManager', string $stdSeverity = 'error', ?string $stdMethod = null, bool $stdRequired = true, ?string $stdTranslationDomain = null): array
	{
		[$class, $defParams, $defErrors] = $this->resolveClass($validator->getAttribute('class'));

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
			if ($argument->hasAttribute('name')) {
				$arguments[$argument->getAttribute('name')] = $argument->getValue();
			} else {
				$arguments[] = $argument->getValue();
			}
		}
		if ($validator->hasChild('arguments')) {
			$parameters['base'] = $validator->getChild('arguments')->getAttribute('base');
			if (!$arguments) { $arguments[] = ''; }
		}

		$errors = $this->getQuioteErrors($validator, $defErrors);
		if ($validator->hasAttribute('required')) {
			$stdRequired = $parameters['required'] = Toolkit::literalize($validator->getAttribute('required'));
		}

		$stdMethod = $validator->getAttribute('method', $stdMethod);
		$methods = [''];
		if (trim((string)$stdMethod)) { $methods = preg_split('/[\s]+/', (string)$stdMethod); }

		if ($validator->hasAttribute('name')) {
			$name = $validator->getAttribute('name');
		} else {
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

		foreach ($methods as $method) {
			$lines = [];
			// Instantiate & initialize
			$validatorVar = '_validator_' . $name;
			$lines[] = sprintf('${%s} = new %s();', var_export($validatorVar, true), $class);
			$lines[] = sprintf('${%s}->initialize($this->getContext(), %s, %s, %s);', var_export($validatorVar, true), var_export($parameters, true), var_export($arguments, true), var_export($errors, true));
			// Add to parent
			if ($parent === 'validationManager') {
				$lines[] = sprintf('$validationManager->addChild(${%s});', var_export($validatorVar, true));
			} else {
				$parentVarToken = '${' . var_export($parent, true) . '}';
				$lines[] = sprintf('${%s}->addChild(${%s});', var_export($parent, true), var_export($validatorVar, true));
			}
			$code[$method][$name . '_' . Toolkit::uniqid()] = implode("\n", $lines);

			// Record declared parameter names for this method's whitelist seed.
			if (!empty($declaredNames)) {
				if (!isset($this->declaredParams[$method])) {
					$this->declaredParams[$method] = [];
				}
				foreach ($declaredNames as $declaredName) {
					$this->declaredParams[$method][] = $declaredName;
				}
			}
		}

		return $this->processValidatorElements($validator, $code, '_validator_' . $name, $parameters['severity'], $stdMethod, $stdRequired, $parameters['translation_domain'] ?? null);
	}

	protected function processValidatorElements($node, array $code, string $name, string $defaultSeverity = 'error', ?string $defaultMethod = null, bool $defaultRequired = true, ?string $defaultTranslationDomain = null): array
	{
		foreach ($node->get('validators') as $validator) {
			if ($validator->parentNode->localName == 'validators') {
				$severity = $validator->parentNode->getAttribute('severity', $defaultSeverity);
				$method = $validator->parentNode->getAttribute('method', $defaultMethod);
				$translationDomain = $validator->parentNode->getAttribute('translation_domain', $defaultTranslationDomain);
			} else {
				$severity = $defaultSeverity;
				$method = $defaultMethod;
				$translationDomain = $defaultTranslationDomain;
			}
			$required = $defaultRequired;
			$code = $this->getValidatorArray($validator, $code, $name, $severity, $method, $required, $translationDomain);
		}
		return $code;
	}

	public function getQuioteErrors(XmlConfigDomElement $node, array $existing = []): array
	{
		$result = $existing;
		$elements = $node->get('errors', self::XML_NAMESPACE);
		foreach ($elements as $element) {
			// New simplified semantics:
			// <error>foo</error>            => ['' => 'foo']
			// <error for="min">bar</error> => ['min' => 'bar']
			// <error name=...> (namespaced multi-domain form) => legacy structured branch
			if ($element->hasAttribute('name')) {
				$name = $element->getAttribute('name');
				$domains = [];
				foreach ($element->get('domain') as $domainElement) {
					$domains[$domainElement->getAttribute('name')] = $domainElement->getValue();
				}
				$result[$name] = [
					'parameters' => $element->getQuioteParameters(isset($result[$name]) ? $result[$name]['parameters'] : []),
					'domains' => $domains,
				];
				continue;
			}
			$val = $element->getValue();
			if ($val === null || $val === '') { continue; }
			if ($element->hasAttribute('for')) {
				$result[$element->getAttribute('for')] = $val;
			} else {
				$result[''] = $val;
			}
		}
		return $result;
	}
}

// Backwards compatibility: global class name
if (!\class_exists('ValidatorConfigHandler', false)) {
	\class_alias(__NAMESPACE__ . '\\ValidatorConfigHandler', 'ValidatorConfigHandler');
}
?>
