<?php

use Agavi\Request\AgaviWebRequestDataHolder;
use Agavi\Testing\AgaviUnitTestCase;

class AgaviWebRequestDataHolderTest extends AgaviUnitTestCase
{
	protected function getDefaultDataHolder()
	{
		return new AgaviWebRequestDataHolder(
			array(
				AgaviWebRequestDataHolder::SOURCE_COOKIES => $this->getDefaultNestedInputData(),
				AgaviWebRequestDataHolder::SOURCE_PARAMETERS => $this->getDefaultNestedInputData(),
				AgaviWebRequestDataHolder::SOURCE_HEADERS => $this->getDefaultHeaders(),
			)
		);
	}
	
	protected function getDefaultNestedInputData()
	{
		return array(
			'flat'   => 'flatvalue',
			'nested' => array(
				'level1' => 'level1 value',
				'level2' => array(
					'level3' => 'level3 value',
					'nullkey' => null,
					'emptystring' => '',
				),
			),
			'nullvalue'   => null,
			'falsevalue'  => false,
			'emptystring' => '',
			'zerovalue'   => 0,
			'objectvalue' => new stdClass(),
		);
	}
	
	public static function parameterData()
	{
		
	}
	
	public static function getParameterReadInformation()
	{
		$readInformation = array();
		$paramData = static::parameterData();
		if(!$paramData) { return $readInformation; }
		foreach($paramData as $key => $parameterInfo) {
			$readInformation[$key] = $parameterInfo;
			$readInformation[$key][4] = false;
			$readInformation[$key.',default'] = $parameterInfo;
			$readInformation[$key.',default'][4] = true;
			if(false == $parameterInfo[2])
			{
				$readInformation[$key.',default'][1] = 'default';
			}
		}
		
		return $readInformation;
	}
	
	public function getFlatDefaultParameterNames()
	{
		return array(
			'flat', 
			'nested[level1]', 
			'nested[level2][level3]', 
			'nested[level2][nullkey]',
			'nested[level2][emptystring]',
			'nullvalue', 
			'falsevalue', 
			'emptystring',
			'zerovalue',
			'objectvalue',
		);
	}
	
	public function getDefaultParameterNames()
	{
		return array('flat', 'nested', 'nullvalue', 'falsevalue', 'emptystring', 'zerovalue', 'objectvalue',);
	}
	
	public function getDefaultHeaders()
	{
		return array(
			'FLAT_HEADER' => 'flatvalue',
			'NESTED_HEADER' => array(          // array headers don't exist, but we need to check 
				'NESTED_KEY' => 'nestedvalue', // that virtual array access does indeed not work
			),
			'NULL_VALUE' => null,
			'ZERO_VALUE' => 0,
			'FALSE_VALUE' => false,
			'EMPTY_STRING' => '',
			'CONTAINS[BRACKETS]' => 'contains_brackets',
		);
	}
}
