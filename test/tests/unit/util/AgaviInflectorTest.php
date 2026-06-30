<?php

use Agavi\Testing\AgaviPhpUnitTestCase;
use Agavi\Util\AgaviInflector;

class AgaviInflectorTest extends AgaviPhpUnitTestCase
{
	#[\PHPUnit\Framework\Attributes\DataProvider('singularPluralTestData')]
	public function testSingularize($singular, $plural)
	{
		$this->assertEquals($singular, AgaviInflector::singularize($plural));
	}
	
	#[\PHPUnit\Framework\Attributes\DataProvider('singularPluralTestData')]
	public function testPluralize($singular, $plural)
	{
		$this->assertEquals($plural, AgaviInflector::pluralize($singular));
	}
	
	public static function singularPluralTestData()
	{
		return [
			["person"      , "people"],
			["man"         , "men"],
			["woman"       , "women"],
			["child"       , "children"],
			["search"      , "searches"],
			["switch"      , "switches"],
			["fix"         , "fixes"],
			["box"         , "boxes"],
			["sex"         , "sexes"],
			["process"     , "processes"],
			["address"     , "addresses"],
			["case"        , "cases"],
			["stack"       , "stacks"],
			["wish"        , "wishes"],
			["fish"        , "fish"],
			["jeans"       , "jeans"],
			["money"       , "money"],
			["my money"    , "my money"],
			["price"       , "prices"],
			["rice"        , "rice"],
			["category"    , "categories"],
			["query"       , "queries"],
			["ability"     , "abilities"],
			["agency"      , "agencies"],
			["movie"       , "movies"],
			["archive"     , "archives"],
			["move"        , "moves"],
			["index"       , "indices"],
			["wife"        , "wives"],
			["safe"        , "saves"],
			["half"        , "halves"],
			["move"        , "moves"],
			["salesperson" , "salespeople"],
			["person"      , "people"],
			["spokesman"   , "spokesmen"],
			["man"         , "men"],
			["woman"       , "women"],
			["basis"       , "bases"],
			["diagnosis"   , "diagnoses"],
			["diagnosis_a" , "diagnosis_as"],
			["datum"       , "data"],
			["medium"      , "media"],
			["stadium"     , "stadia"],
			["analysis"    , "analyses"],
			["node_child"  , "node_children"],
			["child"       , "children"],
			["experience"  , "experiences"],
			["day"         , "days"],
			["comment"     , "comments"],
			["foobar"      , "foobars"],
			["newsletter"  , "newsletters"],
			["old_news"    , "old_news"],
			["news"        , "news"],
			["series"      , "series"],
			["species"     , "species"],
			["quiz"        , "quizzes"],
			["perspective" , "perspectives"],
			["ox"          , "oxen"],
			["zebu ox"     , "zebu oxen"],
			["photo"       , "photos"],
			["buffalo"     , "buffaloes"],
			["tomato"      , "tomatoes"],
			["dwarf"       , "dwarves"],
			["elf"         , "elves"],
			["information" , "information"],
			["equipment"   , "equipment"],
			["bus"         , "buses"],
			["status"      , "statuses"],
			["status_code" , "status_codes"],
			["mouse"       , "mice"],
			["louse"       , "lice"],
			["house"       , "houses"],
			["octopus"     , "octopi"],
			["virus"       , "viri"],
			["alias"       , "aliases"],
			["portfolio"   , "portfolios"],
			["vertex"      , "vertices"],
			["matrix"      , "matrices"],
			["matrix_fu"   , "matrix_fus"],
			["axis"        , "axes"],
			["testis"      , "testes"],
			["crisis"      , "crises"],
			["white-rice"  , "white-rice"],
			["white_rice"  , "white_rice"],
			["rice"        , "rice"],
			["shoe"        , "shoes"],
			["horse"       , "horses"],
			["prize"       , "prizes"],
			["edge"        , "edges"],
			["database"    , "databases"],
			["cookie"      , "cookies"],
			["cache"       , "caches"],
			["|ice"        , "|ices"],
			["|ouse"       , "|ouses"],
			["foot"        , "feet"],
			["cold foot"   , "cold feet"],
			["cold_foot"   , "cold_feet"],
			["bigfoot"     , "bigfoots"],
			["tooth"       , "teeth"],
			["dog_tooth"   , "dog_teeth"],
			["sabertooth"  , "sabertooths"],
			["goose"       , "geese"],
			["mongoose"    , "mongooses"],
			["criterion"   , "criteria"],
			["cherry"      , "cherries"],
			["lady"        , "ladies"],
			["penny"       , "pennies"],
		];
	}
}

?>