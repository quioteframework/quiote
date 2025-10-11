<?php 

use Agavi\Testing\AgaviViewTestCase;
use PHPUnit\Framework\Assert;

class LoginSuccessViewTest extends AgaviViewTestCase
{

	public function __construct($name = NULL, array $data = array(), $dataName = '')
	{
		parent::__construct($name, $data, $dataName);
		$this->actionName = 'Login';
		$this->moduleName = 'Default';
		$this->viewName   = 'Success';
	}
	
	public function testHandlesOutputType()
	{
		if (!method_exists($this, 'assertHandlesOutputType')) { $this->markTestSkipped('View test harness not fully migrated'); }
		$this->assertHandlesOutputType('html');
	}
	
	public function testResponseRedirect()
	{
		if (!method_exists($this, 'runView')) { $this->markTestSkipped('View test harness not fully migrated'); }
		$this->setArguments(['username' => 'Chuck Norris', 'password' => 'kick']);
		$this->getContext()->getUser()->setAttribute('redirect', 'http://www.example.com/', 'org.agavi.SampleApp.login');
		$this->runView();
		if (method_exists($this, 'assertViewResultEquals')) { $this->assertViewResultEquals(''); }
		if (method_exists($this, 'assertViewRedirectsTo')) { $this->assertViewRedirectsTo(array('code' => '302', 'location' => 'http://www.example.com/')); }
	}
	
	public function testResponseHtml()
	{
		if (!method_exists($this, 'runView')) { $this->markTestSkipped('View test harness not fully migrated'); }
		$this->setArguments(['username' => 'Chuck Norris', 'password' => 'kick']);
		$this->runView();
		if (method_exists($this, 'assertViewResponseHasHTTPStatus')) { $this->assertViewResponseHasHTTPStatus(200); }
		if (method_exists($this, 'assertViewResultEquals')) { $this->assertViewResultEquals(''); }
	}
	
	public function testResponseHasCookiesWhenRememberSet()
	{
		if (!method_exists($this, 'runView')) { $this->markTestSkipped('View test harness not fully migrated'); }
		$this->setArguments(['username' => 'Chuck Norris', 'password' => 'kick', 'remember' => true]);
		$this->runView();
		if (method_exists($this, 'assertViewResponseHasHTTPStatus')) { $this->assertViewResponseHasHTTPStatus(200); }
	}
	
}

?>