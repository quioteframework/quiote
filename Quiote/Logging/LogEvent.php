<?php

namespace Quiote\Logging;

/**
 * An immutable structured log event. Nothing is flattened early: the message
 * template and property bag are preserved so structured sinks (JSON) can emit
 * named fields, while text sinks call {@see renderMessage()} to interpolate.
 */
final readonly class LogEvent
{
    /**
     * @param float           $timestamp UNIX timestamp with microseconds (microtime(true)).
     * @param Level           $level
     * @param string          $category
     * @param string          $messageTemplate Raw template, e.g. "Order {orderId} shipped".
     * @param array<string,mixed> $properties  Named properties (PSR-3 $context sans "exception").
     * @param array<string,mixed> $scope       Ambient scope properties merged in at emit time.
     * @param \Throwable|null $exception       From $context['exception'] per PSR-3.
     */
    public function __construct(
        public float $timestamp,
        public Level $level,
        public string $category,
        public string $messageTemplate,
        public array $properties = [],
        public array $scope = [],
        public ?\Throwable $exception = null,
    ) {}

    /**
     * Interpolate {placeholder} tokens in the template using properties then
     * scope. Non-scalar / non-stringable values are left as-is (placeholder
     * kept). Mirrors PSR-3 interpolation semantics.
     */
    public function renderMessage(): string
    {
        if (!str_contains($this->messageTemplate, '{')) {
            return $this->messageTemplate;
        }
        $replacements = [];
        foreach ([...$this->scope, ...$this->properties] as $key => $val) {
            if ($val === null || is_scalar($val) || $val instanceof \Stringable) {
                $replacements['{' . $key . '}'] = (string) $val;
            }
        }
        return $replacements === []
            ? $this->messageTemplate
            : strtr($this->messageTemplate, $replacements);
    }
}
