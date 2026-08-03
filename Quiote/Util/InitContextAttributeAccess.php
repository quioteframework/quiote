<?php

namespace Quiote\Util;

/**
 * The attribute accessor cluster shared by {@see \Quiote\Action\Action} and
 * {@see \Quiote\View\View}: a facade over the attributes an execution's init context holds,
 * so an action or view reads and writes them without knowing where they live.
 *
 * One rule governs every method. Reads answer from {@see $localAttributes} first and fall
 * back to the init context's holder; writes land in the local store whenever it exists, and
 * go to the holder otherwise. A consumer therefore observes its own write through every
 * reader, whichever accessor it used.
 *
 * The local store exists for the container-less execution path, where the init context is an
 * immutable snapshot that cannot be written to. Users that never populate it (an action) get
 * holder-backed behaviour throughout, with no branch of their own.
 *
 * @since      3.2.0
 */
trait InitContextAttributeAccess
{
    /**
     * The consumer's own mutable attribute store, or null when it has none and the init
     * context's holder is the only storage.
     * @var array<int|string, mixed>|null
     */
    protected $localAttributes = null;

    /**
     * The init context's attribute holder, or null when the context is absent or holds no
     * attributes of its own.
     */
    private function attributeHolder(): ?AttributeHolder
    {
        return $this->initContext instanceof AttributeHolder ? $this->initContext : null;
    }

    /**
     * Whether writes are permitted to reach the init context.
     *
     * A {@see \Quiote\Execution\ViewInitContext} is an immutable snapshot of the action's
     * attributes: writing to it would appear to succeed and change nothing, so the attempt
     * is reported once instead.
     */
    private function initContextAcceptsWrites(string $method): bool
    {
        if ($this->initContext instanceof \Quiote\Execution\ViewInitContext) {
            DeprecationSilencer::triggerOnce($method . '() ignored under immutable snapshot');

            return false;
        }

        return true;
    }

    /**
     * @see        AttributeHolder::clearAttributes()
     * @return     void
     * @since      1.0.0
     */
    public function clearAttributes()
    {
        if ($this->localAttributes !== null) {
            $this->localAttributes = [];
        }
        $this->attributeHolder()?->clearAttributes();
    }

    /**
     * @see        AttributeHolder::getAttribute()
     * @param      string $name An attribute name.
     * @param      mixed  $default A default attribute value.
     * @return     mixed
     * @since      1.0.0
     */
    public function &getAttribute($name, $default = null)
    {
        if ($this->localAttributes !== null && array_key_exists($name, $this->localAttributes)) {
            return $this->localAttributes[$name];
        }
        $holder = $this->attributeHolder();
        if ($holder !== null) {
            return $holder->getAttribute($name, null, $default);
        }

        return $default;
    }

    /**
     * @see        AttributeHolder::getAttributeNames()
     * @return     array<int, int|string>
     * @since      1.0.0
     */
    public function getAttributeNames()
    {
        $names = $this->localAttributes !== null ? array_keys($this->localAttributes) : [];
        $holder = $this->attributeHolder();
        if ($holder !== null) {
            $names = array_merge($names, $holder->getAttributeNames() ?? []);
        }

        return array_values(array_unique($names));
    }

    /**
     * @see        AttributeHolder::getAttributes()
     * Returned by reference and aliased to the underlying store, whose declared value type
     * is array<int|string, mixed>; kept consistent with that here.
     * @return     array<int|string, mixed>
     * @since      1.0.0
     */
    public function &getAttributes()
    {
        if ($this->localAttributes !== null) {
            return $this->localAttributes;
        }
        $holder = $this->attributeHolder();
        if ($holder !== null) {
            return $holder->getAttributes();
        }
        $empty = [];

        return $empty;
    }

    /**
     * @see        AttributeHolder::hasAttribute()
     * @param      string $name An attribute name.
     * @return     bool
     * @since      1.0.0
     */
    public function hasAttribute($name)
    {
        if ($this->localAttributes !== null && array_key_exists($name, $this->localAttributes)) {
            return true;
        }

        return $this->attributeHolder()?->hasAttribute($name) ?? false;
    }

    /**
     * @see        AttributeHolder::removeAttribute()
     * @param      string $name An attribute name.
     * @return     mixed The removed value, or null when the name was not set.
     * @since      1.0.0
     */
    public function &removeAttribute($name)
    {
        $removed = null;
        if ($this->localAttributes !== null && array_key_exists($name, $this->localAttributes)) {
            $removed = $this->localAttributes[$name];
            unset($this->localAttributes[$name]);
        }

        $holder = $this->attributeHolder();
        if ($holder !== null) {
            $fromHolder = &$holder->removeAttribute($name);
            if ($removed === null) {
                return $fromHolder;
            }
        }

        return $removed;
    }

    /**
     * @see        AttributeHolder::setAttribute()
     * @param      string $name An attribute name.
     * @param      mixed  $value An attribute value.
     * @return     void
     * @since      1.0.0
     */
    public function setAttribute($name, $value)
    {
        if ($this->localAttributes !== null) {
            $this->localAttributes[$name] = $value;

            return;
        }
        if (!$this->initContextAcceptsWrites('setAttribute')) {
            return;
        }
        $this->attributeHolder()?->setAttribute($name, $value);
    }

    /**
     * @see        AttributeHolder::appendAttribute()
     * @param      string $name An attribute name.
     * @param      mixed  $value An attribute value.
     * @return     void
     * @since      1.0.0
     */
    public function appendAttribute($name, $value)
    {
        if ($this->localAttributes !== null) {
            $list = $this->localListFor($name);
            $list[] = $value;
            $this->localAttributes[$name] = $list;

            return;
        }
        if (!$this->initContextAcceptsWrites('appendAttribute')) {
            return;
        }
        $this->attributeHolder()?->appendAttribute($name, $value);
    }

    /**
     * @see        AttributeHolder::setAttributeByRef()
     * @param      string $name An attribute name.
     * @param      mixed  $value A reference to an attribute value.
     * @return     void
     * @since      1.0.0
     */
    public function setAttributeByRef($name, &$value)
    {
        if ($this->localAttributes !== null) {
            $this->localAttributes[$name] = &$value;

            return;
        }
        if (!$this->initContextAcceptsWrites('setAttributeByRef')) {
            return;
        }
        $this->attributeHolder()?->setAttributeByRef($name, $value);
    }

    /**
     * @see        AttributeHolder::appendAttributeByRef()
     * @param      string $name An attribute name.
     * @param      mixed  $value A reference to an attribute value.
     * @return     void
     * @since      1.0.0
     */
    public function appendAttributeByRef($name, &$value)
    {
        if ($this->localAttributes !== null) {
            $list = $this->localListFor($name);
            // A reference stored inside an array survives the array copy below, so the
            // caller's later writes to $value stay visible through the store.
            $list[] = &$value;
            $this->localAttributes[$name] = $list;

            return;
        }
        if (!$this->initContextAcceptsWrites('appendAttributeByRef')) {
            return;
        }
        $this->attributeHolder()?->appendAttributeByRef($name, $value);
    }

    /**
     * @see        AttributeHolder::setAttributes()
     * @param      array<int|string, mixed> $attributes
     * @return     void
     * @since      1.0.0
     */
    public function setAttributes(array $attributes)
    {
        if ($this->localAttributes !== null) {
            foreach ($attributes as $name => $value) {
                $this->localAttributes[$name] = $value;
            }

            return;
        }
        if (!$this->initContextAcceptsWrites('setAttributes')) {
            return;
        }
        $this->attributeHolder()?->setAttributes($attributes);
    }

    /**
     * @see        AttributeHolder::setAttributesByRef()
     * The references become aliased to the underlying store, whose declared value type is
     * array<int|string, mixed>; kept consistent with that here.
     * @param      array<int|string, mixed> $attributes
     * @return     void
     * @since      1.0.0
     */
    public function setAttributesByRef(array &$attributes)
    {
        if ($this->localAttributes !== null) {
            foreach (array_keys($attributes) as $name) {
                $this->localAttributes[$name] = &$attributes[$name];
            }

            return;
        }
        if (!$this->initContextAcceptsWrites('setAttributesByRef')) {
            return;
        }
        $this->attributeHolder()?->setAttributesByRef($attributes);
    }

    /**
     * The local store's value at $name as a list ready to append to. An existing scalar
     * becomes the list's first element; an unset name yields an empty list.
     *
     * @return     array<int|string, mixed>
     */
    private function localListFor(string|int $name): array
    {
        $existing = $this->localAttributes[$name] ?? null;
        if (is_array($existing)) {
            return $existing;
        }

        return $existing === null ? [] : [$existing];
    }
}
