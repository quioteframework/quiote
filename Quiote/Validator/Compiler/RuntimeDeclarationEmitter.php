<?php

declare(strict_types=1);

namespace Quiote\Validator\Compiler;

use Quiote\Validator\Compiler\Ir\ValidatorNode;
use Quiote\Validator\Compiler\Ir\ValidatorPlan;

/**
 * Turns a {@see ValidatorPlan} into the declaration a compiled validator config returns: the
 * validators to build, in registration order, bucketed by the request method they apply to.
 *
 * The artifact is data, so it cannot register anything by itself --
 * {@see \Quiote\Validator\Compiler\Runtime\ValidatorDeclarationApplier} is what builds and attaches
 * the validators. That is deliberate: a compiled artifact of PHP statements makes a poisoned config
 * cache entry into arbitrary code execution, and this cache is served from APCu, where a poisoned
 * entry never touches disk at all.
 *
 * Bucket keys are request methods, `''` being the bucket that applies to every method. The applier
 * runs the `''` bucket and then the bucket matching the request's method, which is the order the
 * declaration is written in.
 *
 * Within a bucket, order is registration order: a container validator is listed before the children
 * that attach to it, each child naming its parent.
 *
 * @phpstan-type ValidatorDeclaration array{buckets: array<string, array{declaredParameters: list<string>, validators: list<array{name: string, class: string, parameters: array<string, mixed>, arguments: array<int|string, mixed>, errors: array<int|string, mixed>, parent: string|null}>}>}
 * @since      4.0.0
 */
class RuntimeDeclarationEmitter
{
    /**
     * method => validators to build, in traversal order. '' is the methodless/unconditional bucket.
     * @var array<string, list<array{name: string, class: string, parameters: array<string, mixed>, arguments: array<int|string, mixed>, errors: array<int|string, mixed>, parent: string|null}>>
     */
    private array $buckets = [];

    /**
     * method => request parameter names to whitelist for that method, in traversal order
     * (deduped/sorted at emission time).
     * @var array<string, list<string>>
     */
    private array $declaredParams = [];

    /**
     * @return     array{buckets: array<string, array{declaredParameters: list<string>, validators: list<array{name: string, class: string, parameters: array<string, mixed>, arguments: array<int|string, mixed>, errors: array<int|string, mixed>, parent: string|null}>}>}
     * @since      4.0.0
     */
    public function emit(ValidatorPlan $plan): array
    {
        $this->buckets = [];
        $this->declaredParams = [];

        foreach ($plan->nodes as $node) {
            $this->emitNode($node, null);
        }

        // The unconditional bucket first, and always present even when empty: the applier reads it
        // before the method bucket, and an artifact that simply declares nothing is a legitimate
        // outcome (a validators.xml whose validators all carry a method attribute).
        $buckets = ['' => $this->bucket('')];
        foreach (array_keys($this->buckets + $this->declaredParams) as $method) {
            if ($method === '') {
                continue;
            }
            $buckets[(string) $method] = $this->bucket((string) $method);
        }

        return ['buckets' => $buckets];
    }

    /**
     * @return     array{declaredParameters: list<string>, validators: list<array{name: string, class: string, parameters: array<string, mixed>, arguments: array<int|string, mixed>, errors: array<int|string, mixed>, parent: string|null}>}
     * @since      4.0.0
     */
    private function bucket(string $method): array
    {
        return [
            'declaredParameters' => $this->uniqueDeclaredNames($method),
            'validators' => $this->buckets[$method] ?? [],
        ];
    }

    /**
     * @param      ?string $parent The name of the validator this node attaches to, or null for the
     *                    validation manager itself.
     * @since      4.0.0
     */
    private function emitNode(ValidatorNode $node, ?string $parent): void
    {
        foreach ($node->methods as $method) {
            $this->buckets[$method][] = [
                'name' => $node->name,
                'class' => $node->validatorClass,
                'parameters' => $node->parameters,
                'arguments' => $node->arguments,
                'errors' => $node->errors,
                'parent' => $parent,
            ];

            foreach ($node->declaredNames as $declaredName) {
                $this->declaredParams[$method][] = $declaredName;
            }
        }

        foreach ($node->children as $child) {
            $this->emitNode($child, $node->name);
        }
    }

    /**
     * The deduped list of parameter names declared for a given method key. The empty string
     * represents methodless declarations.
     *
     * @return     list<string>
     * @since      4.0.0
     */
    private function uniqueDeclaredNames(string $method): array
    {
        $names = $this->declaredParams[$method] ?? [];
        if ($names === []) {
            return [];
        }

        $unique = array_values(array_unique($names));
        sort($unique);

        return $unique;
    }
}
