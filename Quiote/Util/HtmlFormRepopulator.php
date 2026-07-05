<?php
namespace Quiote\Util;

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
        foreach($xpath->query('//input[@name]') as $input) {
            /** @var \DOMElement $input */
            $type = strtolower($input->getAttribute('type') ?: 'text');
            $name = $input->getAttribute('name');
            if(!array_key_exists($name,$parameters)) { continue; }
            $val = $parameters[$name];
            if(in_array($type, ['checkbox','radio'], true)) {
                if((string)$input->getAttribute('value') === (string)$val) { $input->setAttribute('checked','checked'); }
            } else {
                $input->setAttribute('value', (string)$val);
            }
        }
        foreach($xpath->query('//select[@name]') as $select) {
            /** @var \DOMElement $select */
            $name = $select->getAttribute('name');
            if(!array_key_exists($name,$parameters)) { continue; }
            $val = (string)$parameters[$name];
            foreach($xpath->query('.//option', $select) as $option) {
                /** @var \DOMElement $option */
                if($option->getAttribute('value') === $val) { $option->setAttribute('selected','selected'); }
            }
        }
        if($report) {
            $errors = [];
            foreach($report->getErrors() as $error) { $errors[] = $error->getMessage(); }
            if($errors) {
                $form = $xpath->query('//form')->item(0); if($form) {
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
}
