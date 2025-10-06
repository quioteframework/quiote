<?php

namespace Agavi\Testing;

/**
 * LightweightTestContainer
 *
 * A minimal replacement for the legacy Agavi execution container used only by
 * the PHPUnit test harness. It implements just enough of the old attribute /
 * validation manager surface so that existing tests (assertContainerAttribute*,
 * argument validation assertions, etc.) do not fatally error while the
 * modernization effort removes deep container coupling.
 *
 * Current Scope:
 *  - Attribute holder semantics (namespaces ignored for now – legacy tests in
 *    this codebase appear to use null namespace consistently). If needed this
 *    can be extended trivially to support namespaces by storing nested arrays.
 *  - Request method storage (setRequestMethod/getRequestMethod) to preserve any
 *    reflective test usage.
 *  - Validation manager stub exposing getReport() with the methods accessed by
 *    AgaviActionTestCase (isArgumentValidated / isArgumentFailed).
 *
 * NOTE: The validation report currently returns false for all queries. A later
 * phase will integrate lightweight tracking so performValidation() can record
 * touched / failed arguments if the action validation methods expose that
 * information. For now this silences fatal errors without producing false
 * positives (tests expecting a validated argument will still fail, drawing
 * attention to missing emulation rather than silently passing).
 */
class LightweightTestContainer
{
    /** @var array<string,mixed> */
    protected array $attributes = [];

    /** @var string */
    protected string $requestMethod = 'read';

    /** @var object|null */
    protected ?object $validationManager = null;

    /** @var array|null Snapshot of original parameters prior to validation (legacy container arguments). */
    protected ?array $arguments = null;

    public function __construct()
    {
        // Defer creation of stub until first access unless a real validation manager is injected.
    }

    /* ---------------- Attribute Holder API (namespace ignored) ---------------- */
    public function clearAttributes(): void { $this->attributes = []; }
    public function &getAttribute(string $name, $namespace = null, $default = null)
    {
        if(!array_key_exists($name, $this->attributes)) { $this->attributes[$name] = $default; }
        return $this->attributes[$name];
    }
    public function getAttributeNames(): array { return array_keys($this->attributes); }
    public function &getAttributes(): array { return $this->attributes; }
    public function hasAttribute(string $name, $namespace = null): bool { return array_key_exists($name, $this->attributes); }
    public function &removeAttribute(string $name)
    {
        $ref = $this->attributes[$name] ?? null;
        unset($this->attributes[$name]);
        return $ref; // return previous value (by value semantics retained)
    }
    public function setAttribute(string $name, $value): void { $this->attributes[$name] = $value; }
    public function appendAttribute(string $name, $value): void {
        if(!isset($this->attributes[$name]) || !is_array($this->attributes[$name])) {
            $this->attributes[$name] = [];
        }
        $this->attributes[$name][] = $value;
    }
    public function setAttributeByRef(string $name, &$value): void { $this->attributes[$name] = &$value; }
    public function appendAttributeByRef(string $name, &$value): void {
        if(!isset($this->attributes[$name]) || !is_array($this->attributes[$name])) {
            $this->attributes[$name] = [];
        }
        $this->attributes[$name][] = &$value;
    }
    public function setAttributes(array $attributes): void { $this->attributes = $attributes; }
    public function setAttributesByRef(array &$attributes): void { $this->attributes = &$attributes; }

    /* ---------------- Request Method ---------------- */
    public function setRequestMethod(string $method): void { $this->requestMethod = $method; }
    public function getRequestMethod(): string { return $this->requestMethod; }

    /* ---------------- Arguments (legacy compatibility) ---------------- */
    public function setArguments(array $args): void { $this->arguments = $args; }
    public function getArguments(): ?array { return $this->arguments; }
    public function clearArguments(): void { $this->arguments = null; }

    /* ---------------- Validation Manager Stub ---------------- */
    public function setValidationManager(object $vm): void { $this->validationManager = $vm; }
    public function getValidationManager(): object {
        if ($this->validationManager === null) {
            // Build a stub manager lazily (same shape: has getReport() returning object with required methods)
            $this->validationManager = new class {
                private object $report;
                public function __construct()
                {
                    $this->report = new class {
                        public function isArgumentValidated($arg): bool { return false; }
                        public function isArgumentFailed($arg): bool { return false; }
                        public function getErrorMessages(): array { return []; }
                    };
                }
                public function getReport(): object { return $this->report; }
            };
        }
        return $this->validationManager;
    }
}

?>