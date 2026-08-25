<?php
namespace Quiote\Config\Schema;

/**
 * The kinds of shape a Rule can describe. Struct and Dict are both "map"
 * at the PHP level but mean different things: Struct has a fixed, known
 * key set (a config entry like {class, params}); Dict has dynamic string
 * keys sharing one value shape (e.g. a connection-name-keyed map of
 * database entries). Union is for the genuinely alternative-shaped value --
 * a bool that a `%env(...)%` placeholder may stand in for until load time --
 * as opposed to Mixed, which describes a region that is not checked at all.
 * @since      1.0.0
 */
enum SchemaType
{
	case Struct;
	case Dict;
	case ListOf;
	case String;
	case Bool;
	case Int;
	case PhpClass;
	case Enum;
	case Union;
	case Mixed;
}

?>
