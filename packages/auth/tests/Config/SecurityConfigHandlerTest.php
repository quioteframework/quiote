<?php

use PHPUnit\Framework\TestCase;
use Quiote\Config\Schema\SchemaValidator;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Exception\ParseException;
use Quiote\Security\Auth\Config\SecurityConfigHandler;

class SecurityConfigHandlerTest extends TestCase
{
	private const XML = <<<'XML'
		<?xml version="1.0" encoding="UTF-8"?>
		<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1" xmlns="http://quiote.dev/quiote/config/parts/security/1.1">
			<ae:configuration>
				<password_hashers algorithm="argon2id"/>
				<providers>
					<provider name="app" type="pdo" connection="main" table="users" identifier-column="email" password-column="password_hash"/>
				</providers>
				<firewalls>
					<firewall name="api" pattern="^/api/" stateless="true" entry-point="challenge">
						<authenticator ref="http_basic"/>
					</firewall>
					<firewall name="rpc" pattern="^/rpc/" stateless="true" sessionless="true" entry-point="challenge">
						<authenticator ref="bearer_jwt"/>
					</firewall>
					<firewall name="main" pattern="^/" provider="app" entry-point="login">
						<authenticator ref="form_login"/>
					</firewall>
				</firewalls>
			</ae:configuration>
		</ae:configurations>
		XML;

	private function document(string $xml): XmlConfigDomDocument
	{
		$document = new XmlConfigDomDocument();
		$document->loadXml($xml);
		return $document;
	}

	public function testToCanonicalArrayParsesPasswordHasherProvidersAndFirewalls(): void
	{
		$handler = new SecurityConfigHandler();

		$data = $handler->toCanonicalArray($this->document(self::XML));

		$this->assertSame('argon2id', $data['password_hasher_algorithm']);
		$this->assertSame([
			'type' => 'pdo',
			'connection' => 'main',
			'table' => 'users',
			'identifier_column' => 'email',
			'password_column' => 'password_hash',
		], $data['providers']['app']);

		$this->assertSame([
			'pattern' => '^/api/',
			'stateless' => true,
			'sessionless' => false,
			'entry_point' => 'challenge',
			'provider' => null,
			'authenticators' => ['http_basic'],
		], $data['firewalls']['api']);

		$this->assertTrue($data['firewalls']['rpc']['sessionless']);
		$this->assertSame('app', $data['firewalls']['main']['provider']);
		$this->assertSame(['form_login'], $data['firewalls']['main']['authenticators']);
	}

	public function testToCanonicalArrayThrowsWhenAFirewallIsMissingItsNameAttribute(): void
	{
		$xml = <<<'XML'
			<?xml version="1.0" encoding="UTF-8"?>
			<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1" xmlns="http://quiote.dev/quiote/config/parts/security/1.1">
				<ae:configuration>
					<firewalls>
						<firewall pattern="^/" entry-point="login">
							<authenticator ref="form_login"/>
						</firewall>
					</firewalls>
				</ae:configuration>
			</ae:configurations>
			XML;

		$handler = new SecurityConfigHandler();

		$this->expectException(ParseException::class);
		$handler->toCanonicalArray($this->document($xml));
	}

	public function testExecuteArrayGeneratesReturnableCode(): void
	{
		$handler = new SecurityConfigHandler();
		$config = $handler->toCanonicalArray($this->document(self::XML));

		$code = $handler->executeArray($config);
		$file = tempnam(sys_get_temp_dir(), 'security_config_handler_test');
		$this->assertNotFalse($file);
		file_put_contents($file, $code);
		$decoded = include $file;
		unlink($file);

		$this->assertSame($config, $decoded);
	}

	public function testSchemaValidatesTheCanonicalArray(): void
	{
		$handler = new SecurityConfigHandler();
		$config = $handler->toCanonicalArray($this->document(self::XML));

		$violations = SchemaValidator::validate($handler->schema(), $config);

		$this->assertSame([], $violations);
	}
}
