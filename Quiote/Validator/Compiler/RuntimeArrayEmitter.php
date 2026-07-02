<?php
namespace Quiote\Validator\Compiler;

use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;

/**
 * Emits the PHP snippets that ValidatorConfigHandler has always produced:
 * `new X(); ->initialize(...); ->addChild(...)` calls against
 * $validationManager, wrapped in per-method `if ($method == '...') { ... }`
 * blocks, preceded by declareParameters() whitelist seeds. This is the
 * runtime cache back-end -- it exists to keep the compiled artifact
 * byte-for-byte identical to what the pre-IR handler produced (see the
 * golden-file parity test), not to introduce new behavior.
 * @since      1.0.0
 */
class RuntimeArrayEmitter
{
	/**
	 * method => list of code snippets, in traversal order. '' is the
	 * methodless/unconditional bucket.
	 * @var array<string, string[]>
	 */
	private array $buckets = [];

	/**
	 * method => list of request parameter names to whitelist for that
	 * method, in traversal order (deduped/sorted at emission time).
	 * @var array<string, string[]>
	 */
	private array $declaredParams = [];

	/**
	 * @return string[] Lines of PHP code (pre-header; the caller is
	 *                  responsible for wrapping this in a <?php banner,
	 *                  matching how BaseConfigHandler::generate() has
	 *                  always worked).
	 */
	public function emit(ValidatorPlan $plan): array
	{
		$this->buckets = [];
		$this->declaredParams = [];

		foreach ($plan->nodes as $node) {
			$this->emitNode($node, 'validationManager');
		}

		$final = [];

		// Emit unconditional whitelist seed: declarations that apply regardless
		// of request method. Runs before any conditional method block so the
		// whitelist is populated whether or not a method branch matches.
		$unconditionalNames = $this->uniqueDeclaredNames('');
		if (!empty($unconditionalNames)) {
			$final[] = $this->buildDeclareParametersSnippet($unconditionalNames);
		}

		$buckets = $this->buckets;
		if (isset($buckets[''])) {
			foreach ($buckets[''] as $snippet) { $final[] = $snippet; }
			unset($buckets['']);
		}
		foreach ($buckets as $method => $snippets) {
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

		return $final;
	}

	private function emitNode(ValidatorNode $node, string $parent): void
	{
		$validatorVar = '_validator_' . $node->name;

		foreach ($node->methods as $method) {
			$lines = [];
			$lines[] = sprintf('${%s} = new %s();', var_export($validatorVar, true), $node->validatorClass);
			$lines[] = sprintf(
				'${%s}->initialize($this->getContext(), %s, %s, %s);',
				var_export($validatorVar, true),
				var_export($node->parameters, true),
				var_export($node->arguments, true),
				var_export($node->errors, true)
			);
			if ($parent === 'validationManager') {
				$lines[] = sprintf('$validationManager->addChild(${%s});', var_export($validatorVar, true));
			} else {
				$lines[] = sprintf('${%s}->addChild(${%s});', var_export($parent, true), var_export($validatorVar, true));
			}

			$this->buckets[$method][] = implode("\n", $lines);

			if (!empty($node->declaredNames)) {
				foreach ($node->declaredNames as $declaredName) {
					$this->declaredParams[$method][] = $declaredName;
				}
			}
		}

		foreach ($node->children as $child) {
			$this->emitNode($child, $validatorVar);
		}
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
}
