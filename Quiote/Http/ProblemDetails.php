<?php

namespace Quiote\Http;

use Quiote\Config\Config;

/**
 * An RFC 9457 (Problem Details for HTTP APIs; obsoletes RFC 7807) document.
 * Reusable, response-agnostic value object: build one, then render it with
 * toArray()/toJson() and serve it as `application/problem+json` (see
 * self::MEDIA_TYPE). {@see fromValidationManager()} constructs the common
 * "validation failed" shape, extracting a `field => messages` map from a
 * validation report into the `errors` extension member. */
final readonly class ProblemDetails
{
    public const string MEDIA_TYPE = 'application/problem+json';

    /**
     * @param array<string, string[]> $errors     Extension member: field name => messages ("" = non-field).
     * @param array<string, mixed>    $extensions Additional top-level members (won't override core ones).
     */
    private function __construct(
        private int $status,
        private string $type,
        private string $title,
        private ?string $detail,
        private ?string $instance,
        private array $errors,
        private array $extensions,
    ) {
    }

    /**
     * Create a Problem Details document. When $type is "about:blank" (the RFC
     * default, meaning "no specific problem type"), the title defaults to the HTTP
     * status phrase as the spec recommends; a configured non-blank type may carry
     * a descriptive title.
     * @param array<string, string[]> $errors
     * @param array<string, mixed>    $extensions
     */
    public static function create(
        int $status = 400,
        ?string $title = null,
        ?string $type = null,
        ?string $detail = null,
        ?string $instance = null,
        array $errors = [],
        array $extensions = [],
    ): self {
        $type = ($type !== null && $type !== '')
            ? $type
            : Config::getString('core.problem_details.type', 'about:blank');

        if ($title === null || $title === '') {
            $title = $type === 'about:blank'
                ? self::statusPhrase($status)
                : Config::getString('core.problem_details.title', self::statusPhrase($status));
        }

        return new self(
            $status,
            $type,
            $title,
            ($detail !== null && $detail !== '') ? $detail : null,
            ($instance !== null && $instance !== '') ? $instance : null,
            $errors,
            $extensions,
        );
    }

    /**
     * Build a validation Problem Details document from a validation manager,
     * extracting its incidents into the `errors` map. Pass overrides for title,
     * type, detail, instance, or extra extension members as needed.
     * @param array<string, mixed> $extensions
     */
    public static function fromValidationManager(
        ?object $validationManager,
        int $status = 400,
        ?string $title = null,
        ?string $type = null,
        ?string $detail = null,
        ?string $instance = null,
        array $extensions = [],
    ): self {
        return self::create($status, $title, $type, $detail, $instance, self::extractErrors($validationManager), $extensions);
    }

    /**
     * Extract a `field => messages` map from a validation manager's report.
     * Non-field (model-level) messages are keyed under "".
     * @return array<string, string[]>
     */
    public static function extractErrors(?object $validationManager): array
    {
        $errorsByField = [];
        $add = static function (string $field, string $message) use (&$errorsByField): void {
            if ($message === '') {
                return;
            }
            $errorsByField[$field] ??= [];
            if (!in_array($message, $errorsByField[$field], true)) {
                $errorsByField[$field][] = $message;
            }
        };

        try {
            $report = ($validationManager !== null && method_exists($validationManager, 'getReport'))
                ? $validationManager->getReport()
                : null;
            if ($report !== null) {
                foreach ($report->getIncidents() as $incident) {
                    $fields = [];
                    foreach ($incident->getArguments() as $argument) {
                        $name = (is_object($argument) && method_exists($argument, 'getName')) ? $argument->getName() : null;
                        if (is_string($name) && $name !== '') {
                            $fields[] = $name;
                        }
                    }
                    foreach ($incident->getErrors() as $error) {
                        $message = (is_object($error) && method_exists($error, 'getMessage')) ? (string) $error->getMessage() : '';
                        if ($message === '') {
                            continue;
                        }
                        if ($fields === []) {
                            $add('', $message);
                        } else {
                            foreach ($fields as $field) {
                                $add($field, $message);
                            }
                        }
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $errorsByField;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
        ];
        if ($this->detail !== null) {
            $out['detail'] = $this->detail;
        }
        if ($this->instance !== null) {
            $out['instance'] = $this->instance;
        }
        if ($this->errors !== []) {
            $out['errors'] = $this->errors;
        }
        foreach ($this->extensions as $key => $value) {
            if (!array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public function toJson(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /** @return array<string, string[]> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    private static function statusPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            default => 'Error',
        };
    }
}
