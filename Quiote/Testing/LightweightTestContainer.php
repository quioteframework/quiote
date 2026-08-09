<?php

namespace Quiote\Testing;

/**
 * A minimal stand-in for the execution container, used only by the PHPUnit test
 * harness. It implements just enough of the attribute and validation-manager
 * surface that tests exercising them — assertContainerAttribute*, argument
 * validation assertions — run without fatally erroring.
 *
 * Scope:
 *  - Attribute holder semantics. Namespaces are ignored; tests in this codebase
 *    use a null namespace consistently. Supporting them would mean storing
 *    nested arrays.
 *  - Request method storage (setRequestMethod/getRequestMethod), for reflective
 *    test usage.
 *  - A validation manager stub exposing getReport() with the two methods
 *    ActionTestCase reads: isArgumentValidated() and isArgumentFailed().
 *
 * The validation report answers false to every query. A test expecting a
 * validated argument therefore fails rather than silently passing, which points
 * at the missing emulation instead of hiding it.
 */
class LightweightTestContainer
{
    /** @var array<string,mixed> */
    protected array $attributes = [];

    /** @var string */
    protected string $requestMethod = 'read';

    /** @var object|null */
    protected ?object $validationManager = null;

    /** @var array<string,mixed>|null Snapshot of original parameters prior to validation (legacy container arguments). */
    protected ?array $arguments = null;

    public function __construct()
    {
        // Defer creation of stub until first access unless a real validation manager is injected.
    }

    /* ---------------- Attribute Holder API (namespace ignored) ---------------- */
    /** Removes every attribute, leaving getAttributeNames() empty. The request method and stored arguments are untouched. */
    public function clearAttributes(): void { $this->attributes = []; }
    /**
     * @param mixed $namespace
     * @param mixed $default
     * @return mixed
     */
    public function &getAttribute(string $name, $namespace = null, $default = null)
    {
        if(!array_key_exists($name, $this->attributes)) { $this->attributes[$name] = $default; }
        return $this->attributes[$name];
    }
    /** @return string[] */
    public function getAttributeNames(): array { return array_keys($this->attributes); }
    /** @return array<string,mixed> */
    public function &getAttributes(): array { return $this->attributes; }
    /** @param mixed $namespace */
    public function hasAttribute(string $name, $namespace = null): bool { return array_key_exists($name, $this->attributes); }
    /** @return mixed */
    public function &removeAttribute(string $name)
    {
        $ref = $this->attributes[$name] ?? null;
        unset($this->attributes[$name]);
        return $ref; // return previous value (by value semantics retained)
    }
    /** @param mixed $value */
    public function setAttribute(string $name, $value): void { $this->attributes[$name] = $value; }
    /** @param mixed $value */
    public function appendAttribute(string $name, $value): void {
        if(!isset($this->attributes[$name]) || !is_array($this->attributes[$name])) {
            $this->attributes[$name] = [];
        }
        $this->attributes[$name][] = $value;
    }
    /** @param mixed $value */
    public function setAttributeByRef(string $name, &$value): void { $this->attributes[$name] = &$value; }
    /** @param mixed $value */
    public function appendAttributeByRef(string $name, &$value): void {
        if(!isset($this->attributes[$name]) || !is_array($this->attributes[$name])) {
            $this->attributes[$name] = [];
        }
        $this->attributes[$name][] = &$value;
    }
    /** @param array<string,mixed> $attributes */
    public function setAttributes(array $attributes): void { $this->attributes = $attributes; }
    /** @param array<string,mixed> $attributes */
    public function setAttributesByRef(array &$attributes): void { $this->attributes = &$attributes; }

    /* ---------------- Request Method ---------------- */
    /** Sets the request method getRequestMethod() reports. The value is stored verbatim; nothing validates or normalizes it. */
    public function setRequestMethod(string $method): void { $this->requestMethod = $method; }
    /** Returns the request method the container was told to report; `read` until setRequestMethod() says otherwise. */
    public function getRequestMethod(): string { return $this->requestMethod; }

    /* ---------------- Arguments (legacy compatibility) ---------------- */
    /** @param array<string,mixed> $args */
    public function setArguments(array $args): void { $this->arguments = $args; }
    /** @return array<string,mixed>|null */
    public function getArguments(): ?array { return $this->arguments; }
    /** Drops the stored argument snapshot, so getArguments() reports null again rather than an empty array. */
    public function clearArguments(): void { $this->arguments = null; }

    /* ---------------- Validation Manager Stub ---------------- */
    /**
     * Injects the validation manager getValidationManager() returns.
     *
     * Call this before the first getValidationManager() to keep the always-false stub from
     * being built; calling it afterwards replaces the stub for subsequent reads. Any object
     * exposing `getReport()` is accepted — the container never inspects it.
     */
    public function setValidationManager(object $vm): void { $this->validationManager = $vm; }
    /**
     * Returns the validation manager, building a stub on first access if none was injected.
     *
     * The stub is created lazily and kept, so the same instance — and the same report — is returned
     * on every call. Its report answers false to every query, so a test that expects an argument to
     * have been validated fails rather than passing on emulation.
     */
    public function getValidationManager(): object {
        if ($this->validationManager === null) {
            // Build a stub manager lazily (same shape: has getReport() returning object with required methods)
            $this->validationManager = new readonly class {
                private object $report;
                public function __construct()
                {
                    $this->report = new class {
                        /** Always false: this stub records nothing, so no argument is ever reported as validated. */
                        public function isArgumentValidated(mixed $arg): bool { return false; }
                        /** Always false: this stub records nothing, so no argument is ever reported as failed. */
                        public function isArgumentFailed(mixed $arg): bool { return false; }
                        /** @return array<int, string> */
                        public function getErrorMessages(): array { return []; }
                    };
                }
                /** Returns the stub report exposing isArgumentValidated(), isArgumentFailed() and getErrorMessages(). */
                public function getReport(): object { return $this->report; }
            };
        }
        return $this->validationManager;
    }
}

?>