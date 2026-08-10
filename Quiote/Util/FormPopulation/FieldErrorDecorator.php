<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Util\Toolkit;

/**
 * Marks a field that failed validation, by putting the configured error class
 * on it and on whatever else the error class map points at.
 *
 * The field itself is not always what should be styled: a designer may want the
 * class on a wrapping div, or on the label rather than the input. So the map is
 * XPath keyed -- each expression is evaluated from the field (and from each of
 * its labels) and the class lands on whatever it selects. The first expression
 * that selects anything wins, which is what makes the map an ordered list of
 * increasingly general fallbacks rather than a set of independent rules.
 *
 * Labels are collected two ways because HTML allows both: a label wrapping the
 * input, and one elsewhere pointing at it by `for`.
 */
final readonly class FieldErrorDecorator
{
    /**
     * @param \Closure(string, ?\DOMElement): array<int, \DOMElement> $queryElements
     */
    public function __construct(
        private \Closure $queryElements,
        private string $xmlnsPrefix,
    ) {}

    /**
     * @param array<string, mixed> $errorClassMap XPath expression => class name.
     */
    public function decorate(\DOMElement $element, \DOMElement $form, array $errorClassMap): void
    {
        foreach ($this->targetsFor($element, $form) as $target) {
            foreach ($errorClassMap as $expression => $className) {
                $expanded = Toolkit::expandVariables(
                    self::asString($expression),
                    ['htmlnsPrefix' => $this->xmlnsPrefix]
                );

                $selected = ($this->queryElements)($expanded, $target);
                if ($selected === []) {
                    continue;
                }

                foreach ($selected as $destination) {
                    self::addClass($destination, self::asString($className));
                }

                // This expression matched, so no more general fallback is consulted.
                break;
            }
        }
    }

    /**
     * The element itself plus every label bound to it, implicit or explicit.
     *
     * @return array<int, \DOMElement>
     */
    private function targetsFor(\DOMElement $element, \DOMElement $form): array
    {
        $targets = [$element];

        foreach (($this->queryElements)(sprintf('ancestor::%slabel[not(@for)]', $this->xmlnsPrefix), $element) as $label) {
            $targets[] = $label;
        }

        $id = $element->getAttribute('id');
        if ($id !== '') {
            // "//" rather than "descendant::": an explicit label need not live inside the form.
            foreach (($this->queryElements)(sprintf('//%slabel[@for="%s"]', $this->xmlnsPrefix, $id), $form) as $label) {
                $targets[] = $label;
            }
        }

        return $targets;
    }

    /** Appends to whatever classes the template already put on the element. */
    private static function addClass(\DOMElement $element, string $className): void
    {
        $existing = $element->getAttribute('class');
        $element->setAttribute('class', preg_replace('/\s*$/', ' ' . $className, $existing) ?? ($existing . ' ' . $className));
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
