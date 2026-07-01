<?php
class SampleAppUser extends RbacSecurityUser
{
	/**
	 * Let's pretend this is our database. For the sake of example ;)
	 */
	static $users = [
		'Chuck Norris' => [
			'password' => '$2a$10$2/Gmc4XpwAytFgy3wfrW9OUnkzd6ahgcMqrm4cEc4zD3IFD1GB6IG', // bcrypt, 10 rounds, "kick"
			'roles' => [
				'photographer',
			]
		],
	];
	
	public function startup()
	{
		parent::startup();
		
		$reqData = $this->getContext()->getRequest()->getRequestData();
		
		if(!$this->isAuthenticated() && $reqData->hasCookie('autologon')) {
			$login = $reqData->getCookie('autologon');
			try {
				$this->login($login['username'], $login['password'], true);
			} catch(SecurityException) {
				$response = $this->getContext()->getController()->getGlobalResponse();
				// login didn't work. that cookie sucks, delete it.
				$response->setCookie('autologon[username]', false);
				$response->setCookie('autologon[password]', false);
			}
		}
	}
	
	public function login($username, $password, $isPasswordHashed = false)
	{
		if(!isset(self::$users[$username])) {
			throw new SecurityException('username');
		}
		
		if(!$isPasswordHashed) {
			$password = self::computeSaltedHash($password, self::$users[$username]['password']);
		}
		
		if($password != self::$users[$username]['password']) {
			throw new SecurityException('password');
		}
		
		$this->setAuthenticated(true);
		$this->clearCredentials();
		$this->grantRoles(self::$users[$username]['roles']);
	}
	
	public static function computeSaltedHash($secret, $salt)
	{
		return crypt((string) $secret, (string) $salt);
	}
	
	public static function getPassword($username)
	{
		if(self::$users[$username]) {
			return self::$users[$username]['password'];
		}
	}
	
	public function logout()
	{
		$this->clearCredentials();
		$this->setAuthenticated(false);
	}
}

?>