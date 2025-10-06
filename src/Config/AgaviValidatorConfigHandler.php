<?php
// Minimal, instrumentation-free validator config handler (restored simplified semantics)
namespace Agavi\Config;

use Agavi\Config\Util\DOM\AgaviXmlConfigDomDocument;
use Agavi\Config\Util\DOM\AgaviXmlConfigDomElement;
use Agavi\Util\AgaviToolkit;

class AgaviValidatorConfigHandler extends AgaviXmlConfigHandler
{
	const XML_NAMESPACE = 'http://agavi.org/agavi/config/parts/validators/1.1';

	/**
	 * name => [class, parameters, errors]
	 * (definition name, not necessarily the real PHP class)
	 */
	protected array $classMap = [];

	public function execute(AgaviXmlConfigDomDocument $document): string
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'validators');
		$config = $document->documentURI;
		$code = [];

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
					$this->classMap[$name]['parameters'] = $def->getAgaviParameters($this->classMap[$name]['parameters']);
					$this->classMap[$name]['errors'] = $this->getAgaviErrors($def, $this->classMap[$name]['errors']);
				}
			}
			$code = $this->processValidatorElements($cfg, $code, 'validationManager');
		}

		$final = [];
		if (isset($code[''])) {
			$final = $code[''];
			unset($code['']);
		}
		foreach ($code as $method => $snippets) {
			$final[] = 'if($method == ' . var_export($method, true) . '){';
			foreach ($snippets as $snippet) { $final[] = $snippet; }
			$final[] = '}';
		}
		return $this->generate($final, $config);
	}

	protected function resolveClass(string $declared): array
	{
		// If declared token is a definition name, return mapped; else treat as direct class
		if (isset($this->classMap[$declared]) && $this->classMap[$declared]['class'] !== $declared) {
			$def = $this->classMap[$declared];
			return [
				$def['class'],
				$def['parameters'],
				$def['errors']
			];
		}
		if (isset($this->classMap[$declared])) {
			$def = $this->classMap[$declared];
			return [
				$def['class'],
				$def['parameters'],
				$def['errors']
			];
		}
		// Not defined: treat as direct class (backwards-compatible)
		return [$declared, [], []];
	}

	protected function getValidatorArray(AgaviXmlConfigDomElement $validator, array $code, string $parent = 'validationManager', string $stdSeverity = 'error', ?string $stdMethod = null, bool $stdRequired = true, ?string $stdTranslationDomain = null): array
	{
		[$class, $defParams, $defErrors] = $this->resolveClass($validator->getAttribute('class'));

		$parameters = array_merge($defParams, [
			'severity' => $validator->getAttribute('severity', $stdSeverity),
			'required' => $stdRequired,
		], $validator->getAttributes());

		$parameters = $validator->getAgaviParameters($parameters);
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

		$errors = $this->getAgaviErrors($validator, $defErrors);
		if ($validator->hasAttribute('required')) {
			$stdRequired = $parameters['required'] = AgaviToolkit::literalize($validator->getAttribute('required'));
		}

		$stdMethod = $validator->getAttribute('method', $stdMethod);
		$methods = [''];
		if (trim((string)$stdMethod)) { $methods = preg_split('/[\s]+/', (string)$stdMethod); }

		if ($validator->hasAttribute('name')) {
			$name = $validator->getAttribute('name');
		} else {
			$name = AgaviToolkit::uniqid();
			$validator->setAttribute('name', $name);
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
			$code[$method][$name . '_' . AgaviToolkit::uniqid()] = implode("\n", $lines);
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

	public function getAgaviErrors(AgaviXmlConfigDomElement $node, array $existing = []): array
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
					'parameters' => $element->getAgaviParameters(isset($result[$name]) ? $result[$name]['parameters'] : []),
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
if (!\class_exists('AgaviValidatorConfigHandler', false)) {
	\class_alias(__NAMESPACE__ . '\\AgaviValidatorConfigHandler', 'AgaviValidatorConfigHandler');
}
?>
