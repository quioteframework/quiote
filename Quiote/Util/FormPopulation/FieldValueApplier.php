<?php

declare(strict_types=1);

namespace Quiote\Util\FormPopulation;

use Quiote\Util\ParameterHolder;

/**
 * Writes a submitted value onto the form element that carries it.
 *
 * Each control type says "this is my value" differently -- an attribute for a
 * text input, the presence of `checked` for a checkbox, `selected` on a child
 * option for a select, and the element's text for a textarea -- so this is
 * where knowledge of those four shapes lives.
 *
 * Removing the rendered state before setting the new one is deliberate
 * throughout: a checkbox the view rendered as checked must come back unchecked
 * when the submission did not include it, and a value the view rendered must
 * not survive a submission that cleared the field.
 */
final readonly class FieldValueApplier
{
    /**
     * @param \Closure(string, ?\DOMElement): array<int, \DOMElement> $queryElements XPath against the document.
     */
    public function __construct(
        private \DOMDocument $document,
        private DocumentEncoding $encoding,
        private string $xmlnsPrefix,
        private bool $useCdataForTextareas,
        private bool $includeHiddenInputs,
        private bool $includePasswordInputs,
        private \Closure $queryElements,
    ) {}

    /**
     * Applies the submitted value for $name to $element.
     *
     * @return bool False when the element was left untouched, so the caller
     *              knows nothing was written for it.
     */
    public function apply(
        \DOMElement $element,
        ResolvedFieldName $name,
        mixed $value,
        ParameterHolder $parameters,
    ): bool {
        return match ($element->nodeName) {
            'input' => $this->applyToInput($element, $name, $value, $parameters),
            'select' => $this->applyToSelect($element, $name, $value, $parameters),
            'textarea' => $this->applyToTextarea($element, $value),
            default => false,
        };
    }

    private function applyToInput(
        \DOMElement $element,
        ResolvedFieldName $name,
        mixed $value,
        ParameterHolder $parameters,
    ): bool {
        $type = $element->getAttribute('type');

        if ($type === 'checkbox' || $type === 'radio') {
            return $this->applyToCheckable($element, $name, $value, $parameters);
        }

        // A button carries its own label as its value; overwriting it would relabel the control.
        if ($type === 'button' || $type === 'submit') {
            return false;
        }

        if (!$this->includeHiddenInputs && $type === 'hidden') {
            return false;
        }

        $element->removeAttribute('value');

        if ($parameters->hasParameter($name->path) && ($this->includePasswordInputs || $type !== 'password')) {
            $element->setAttribute('value', self::asString($value));

            return true;
        }

        return false;
    }

    private function applyToCheckable(
        \DOMElement $element,
        ResolvedFieldName $name,
        mixed $value,
        ParameterHolder $parameters,
    ): bool {
        $element->removeAttribute('checked');

        if ($name->groupsByValue && is_array($value)) {
            // The submission is the list of checked values, so this element is checked
            // exactly when its own value is one of them.
            $ownValue = $element->getAttribute('value');
            if (!$this->encoding->isUtf8) {
                $ownValue = self::asString($this->encoding->fromUtf8($ownValue));
            }

            if (!in_array($ownValue, $value)) {
                return false;
            }

            $element->setAttribute('checked', 'checked');

            return true;
        }

        if (!$parameters->hasParameter($name->path)) {
            return false;
        }

        $matchesOwnValue = $element->hasAttribute('value') && $element->getAttribute('value') == $value;
        $valuelessAndTruthy = !$element->hasAttribute('value') && $parameters->getParameter($name->path);

        if ($matchesOwnValue || $valuelessAndTruthy) {
            $element->setAttribute('checked', 'checked');

            return true;
        }

        return false;
    }

    private function applyToSelect(
        \DOMElement $element,
        ResolvedFieldName $name,
        mixed $value,
        ParameterHolder $parameters,
    ): bool {
        $multiple = $element->hasAttribute('multiple');
        $applied = false;

        // XPath rather than childNodes: options may sit inside an optgroup.
        foreach (($this->queryElements)(sprintf('descendant::%soption', $this->xmlnsPrefix), $element) as $option) {
            $option->removeAttribute('selected');

            if (!$parameters->hasParameter($name->path)) {
                continue;
            }

            $optionValue = $option->getAttribute('value');
            $selected = $optionValue === $value
                || ($multiple && is_array($value) && in_array($optionValue, $value));

            if ($selected) {
                $option->setAttribute('selected', 'selected');
                $applied = true;
            }
        }

        return $applied;
    }

    private function applyToTextarea(\DOMElement $element, mixed $value): bool
    {
        while ($element->firstChild !== null) {
            $element->removeChild($element->firstChild);
        }

        $text = self::asString($value);
        $node = $this->useCdataForTextareas
            ? $this->document->createCDATASection($text)
            : $this->document->createTextNode($text);

        if ($node === false) {
            // DOM refused to build the node, so the textarea keeps the emptying above and
            // nothing further is written -- reported so the caller does not read it as applied.
            return false;
        }

        $element->appendChild($node);

        return true;
    }

    private static function asString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
