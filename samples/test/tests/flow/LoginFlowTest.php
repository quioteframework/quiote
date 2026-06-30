<?php 


/**
 * @agaviRoutingInput /en/auth/login
 */
class LoginFlowTest extends AgaviFlowTestCase
{
	
	/**
	 * @agaviRequestMethod write
	 */
	public function testValidWriteRequest()
	{
		$this->dispatch(['username' => 'Chuck Norris', 'password' => 'kick']);
		$this->assertResponseHasTag(['tag' => 'body']);
		$this->assertResponseHasTag(['tag' => 'h2', 'content' => 'Login Successful']);
	}
	
	/**
	 * @agaviRequestMethod write
	 */
	public function testInvalidWriteRequest()
	{
		$this->dispatch(['username' => 'Chuck Norris', 'password' => 'foo']);
		$this->assertResponseHasTag(['tag' => 'body']);
		$this->assertResponseHasNotTag(['tag' => 'h2', 'content' => 'Login Successful']);
		$this->assertResponseHasTag(['tag' => 'p', 'content' => 'Wrong Password']);
	}
}

?>