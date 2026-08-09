<?php

/**
 * google/protobuf declares `Google\Protobuf\Internal\RepeatedField` through a
 * `class_alias()` call at the foot of `Google\Protobuf\RepeatedField`. PHPStan
 * does not follow `class_alias()`, and open-telemetry/gen-otlp-protobuf's
 * generated accessors annotate their return types with the aliased name, so
 * without this declaration every OTLP repeated-field getter reads as an unknown
 * class and drags every caller down with it.
 *
 * Pulled in via `scanFiles` rather than `stubFiles`, which only augments classes
 * that already exist under the name being declared. Never loaded at runtime: at
 * runtime the alias is the real class, and this file would collide with it.
 */

namespace Google\Protobuf\Internal;

/**
 * @template T
 * @extends \Google\Protobuf\RepeatedField<T>
 */
class RepeatedField extends \Google\Protobuf\RepeatedField
{
    /**
     * The real constructor takes the element's GPBType and, for message fields,
     * its class name. Naming that second argument `class-string<T>` is what lets
     * the element type be inferred at a construction site, which the generated
     * OTLP setters demand of anything handed to them.
     *
     * @param int $type
     * @param class-string<T>|null $klass
     */
    public function __construct($type, $klass = null)
    {
        parent::__construct($type, $klass);
    }
}
