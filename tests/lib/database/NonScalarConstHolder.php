<?php

namespace Quiote\Test\Database;

/**
 * A fixture class exposing a class constant whose value is neither an int nor
 * a string, used to exercise PdoDatabase's "Class::CONST" option/attribute
 * resolution failure path without depending on any real PDO constant.
 */
final class NonScalarConstHolder
{
    public const array ARR = ['not', 'scalar'];
}
