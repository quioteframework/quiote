<?php
namespace Quiote\Util;

use InvalidArgumentException;
use Quiote\Validator\ValidationReport;

/**
 * Lightweight HTML form repopulation utility replacing FormPopulationFilter for container-less pipeline.
 * Supports input[type=text], input[type=checkbox|radio], select/option population and simple global error list.
 */
final class HtmlFormRepopulator
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $config
     */
    public static function repopulate(string $html, array $parameters, ?ValidationReport $report = null, array $config = []): string
    {
        if($html === '') { return $html; }
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        foreach(self::queryElements($xpath, '//input[@name]') as $input) {
            $type = strtolower($input->getAttribute('type') ?: 'text');
            $name = $input->getAttribute('name');
            if(!array_key_exists($name,$parameters)) { continue; }
            $val = self::toStringValue($parameters[$name], $name);
            if(in_array($type, ['checkbox','radio'], true)) {
                if($input->getAttribute('value') === $val) { $input->setAttribute('checked','checked'); }
            } else {
                $input->setAttribute('value', $val);
            }
        }
        foreach(self::queryElements($xpath, '//select[@name]') as $select) {
            $name = $select->getAttribute('name');
            if(!array_key_exists($name,$parameters)) { continue; }
            $val = self::toStringValue($parameters[$name], $name);
            foreach(self::queryElements($xpath, './/option', $select) as $option) {
                if($option->getAttribute('value') === $val) { $option->setAttribute('selected','selected'); }
            }
        }
        if($report) {
            $errors = [];
            foreach($report->getErrors() as $error) { $errors[] = $error->getMessage(); }
            if($errors) {
                $forms = self::queryElements($xpath, '//form');
                $form = $forms[0] ?? null;
                if($form) {
                    $ul = $dom->createElement('ul');
                    foreach($errors as $e) { $li = $dom->createElement('li'); $li->appendChild($dom->createTextNode($e)); $ul->appendChild($li); }
                    $form->insertBefore($ul, $form->firstChild);
                }
            }
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if(!$body) { return $html; }
        $inner = '';
        foreach($body->childNodes as $child) { $inner .= $dom->saveHTML($child); }
        return '<!DOCTYPE html><html><body>' . $inner . '</body></html>';
    }

    /**
     * Coerces a repopulation value to a string, the same way it will end up
     * being written into a "value"/"checked" HTML attribute.
     * @throws InvalidArgumentException when the value has no meaningful string representation
     */
    private static function toStringValue(mixed $value, string $name): string
    {
        if(is_string($value)) {
            return $value;
        }
        if(is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        throw new InvalidArgumentException(sprintf(
            'Cannot repopulate field "%s": expected a scalar or Stringable value, got %s',
            $name,
            get_debug_type($value)
        ));
    }

    /**
     * Runs an XPath query and returns the matched elements as a plain array.
     * DOMXPath::query() can return false (invalid expression) or, per its own
     * axis support, namespace nodes; neither of those are ever produced by
     * the element-only expressions used above, so both are filtered out here.
     * @return array<int, \DOMElement>
     */
    private static function queryElements(\DOMXPath $xpath, string $expression, ?\DOMNode $contextNode = null): array
    {
        $result = $xpath->query($expression, $contextNode);
        if($result === false) {
            return [];
        }
        $elements = [];
        foreach($result as $node) {
            if($node instanceof \DOMElement) {
                $elements[] = $node;
            }
        }
        return $elements;
    }
}
