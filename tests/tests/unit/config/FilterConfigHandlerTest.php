<?php

use Quiote\Config\Config;
use Quiote\Config\FilterConfigHandler;
use Quiote\Filter\IFilter;
use Quiote\Filter\FilterChain;
use Quiote\Context;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class FCHTestFilter1 implements IFilter
{
	public $context;
	public $params;

	public function initialize(Context $ctx, array $params = [])
	{
		$this->context = $ctx;
		$this->params = $params;
	}

	public function executeOnce(FilterChain $filterChain, $container) {}
	public function execute(FilterChain $filterChain, $container) {}
	public final function getContext() {}
}

class FCHTestFilter2 extends FCHTestFilter1
{
}

class FilterConfigHandlerTest extends ConfigHandlerTestBase
{
	protected $context;

	protected function getContext()
	{
		// Disable translation system for this test suite while translation/i18n rewrite pending
		Config::set('core.use_translation', false, true);
		$context = Context::getInstance('test');
		return $context;
	}

	#[\Override]
    public function setUp(): void
	{
		$this->context = $this->getContext();
	}

	public function testFilterConfigHandler()
	{
		$ctx = $this->getContext();
		
		$FCH = new FilterConfigHandler();
		
		$document = $this->parseConfiguration(
			Config::get('core.config_dir') . '/tests/filters.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/filters.xsl'
		);

		$filters = [];

		$file = $this->getIncludeFile($FCH->execute($document));
		include($file);
		unlink($file);

		$this->assertCount(2, $filters);

		$this->assertInstanceOf('FCHTestFilter1', $filters['filter1']);
		$this->assertSame(['comment' => true], $filters['filter1']->params);
		$this->assertSame($ctx, $filters['filter1']->context);

		$this->assertInstanceOf('FCHTestFilter2', $filters['filter2']);
		$this->assertSame([], $filters['filter2']->params);
		$this->assertSame($ctx, $filters['filter2']->context);
	}
}
?>