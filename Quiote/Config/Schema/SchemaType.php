<?php
namespace Quiote\Config\Schema;

/**
 * The kinds of shape a Rule can describe. Struct and Dict are both "map"
 * at the PHP level but mean different things: Struct has a fixed, known
 * key set (a config entry like {class, params}); Dict has dynamic string
 * keys sharing one value shape (e.g. a connection-name-keyed map of
 * database entries).
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
	case Mixed;
}

?>
