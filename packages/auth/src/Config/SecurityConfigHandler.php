<?php
namespace Quiote\Security\Auth\Config;

use Quiote\Config\Format\Xml\ElementPositionIndex;
use Quiote\Config\IArrayConfigHandler;
use Quiote\Config\IPositionAwareConfigHandler;
use Quiote\Config\ISchemaAwareConfigHandler;
use Quiote\Config\Schema\Rule;
use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Config\XmlConfigHandler;
use Quiote\Exception\ParseException;

/**
 * Parses a `security.{php,xml,yml,yaml}` file -- `<password_hashers>`,
 * `<providers>`, `<firewalls>` (each `<firewall>` carrying `pattern`,
 * `stateless`, `sessionless`, `entry-point`, `provider`, and an ordered
 * list of `<authenticator ref="...">` elements) -- into a canonical array
 * of password-hasher/provider/firewall definitions. This handler only
 * produces that array -- turning it into live `Firewall`/
 * `AuthenticatorInterface` objects is `FirewallFactory`'s job, kept
 * separate so apps that assemble firewalls purely in PHP never need this
 * class at all.
 *
 * Not wired into `Quiote\Config\defaults\config_handlers.xml` (that file is
 * core-only): a consuming app registers a `<handler pattern="..."
 * class="Quiote\Security\Auth\Config\SecurityConfigHandler">` entry in its
 * own `config_handlers.xml`, exactly as any other non-core-default config
 * kind does (see `Quiote\Config\RbacDefinitionConfigHandler` for the
 * identical, core-default case of this same mechanism).
 * @since      1.0.0
 */
class SecurityConfigHandler extends XmlConfigHandler implements IArrayConfigHandler, ISchemaAwareConfigHandler, IPositionAwareConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/security/1.1';

	/**
	 * @return     Rule The structural schema for the canonical array returned by toCanonicalArray().
	 * @since      1.0.0
	 */
	public function schema(): Rule
	{
		$providerSchema = Rule::struct([
			'type' => Rule::enumOf(['in_memory', 'pdo', 'callable']),
			'connection' => Rule::string(nullable: true),
			'table' => Rule::string(nullable: true),
			'identifier_column' => Rule::string(nullable: true),
			'password_column' => Rule::string(nullable: true),
		], required: ['type']);

		$firewallSchema = Rule::struct([
			'pattern' => Rule::string(),
			'stateless' => Rule::bool(),
			'sessionless' => Rule::bool(),
			'entry_point' => Rule::string(nullable: true),
			'provider' => Rule::string(nullable: true),
			'authenticators' => Rule::listOf(Rule::string()),
		], required: ['pattern', 'stateless', 'sessionless', 'authenticators']);

		return Rule::struct([
			'password_hasher_algorithm' => Rule::string(nullable: true),
			'providers' => Rule::dictOf($providerSchema),
			'firewalls' => Rule::dictOf($firewallSchema),
		], required: ['providers', 'firewalls']);
	}

	/**
	 * @param      XmlConfigDomDocument $document The parsed `security.xml` document.
	 * @return     string Compiled PHP code returning the canonical array (see toCanonicalArray()).
	 * @throws     \Quiote\Exception\UnreadableException If a requested configuration file does not exist or is not readable.
	 * @throws     ParseException If a requested configuration file is improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @param      XmlConfigDomDocument $document The parsed `security.xml` document.
	 * @return array{
	 *     password_hasher_algorithm: ?string,
	 *     providers: array<string, array{type: string, connection: ?string, table: ?string, identifier_column: ?string, password_column: ?string}>,
	 *     firewalls: array<string, array{pattern: string, stateless: bool, sessionless: bool, entry_point: ?string, provider: ?string, authenticators: array<int, string>}>,
	 * }
	 * @since      1.0.0
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'security');

		$data = ['password_hasher_algorithm' => null, 'providers' => [], 'firewalls' => []];

		foreach($document->getConfigurationElements() as $cfg) {
			$hashers = $cfg->getChild('password_hashers');
			if($hashers !== null) {
				$data['password_hasher_algorithm'] = $hashers->getAttribute('algorithm');
			}

			if($cfg->has('providers')) {
				foreach($cfg->get('providers') as $provider) {
					$data['providers'][$this->requireAttribute($provider, 'name', $document->documentURI)] = $this->parseProvider($provider);
				}
			}

			if($cfg->has('firewalls')) {
				foreach($cfg->get('firewalls') as $firewall) {
					$data['firewalls'][$this->requireAttribute($firewall, 'name', $document->documentURI)] = $this->parseFirewall($firewall);
				}
			}
		}

		return $data;
	}

	/**
	 * @param      array{password_hasher_algorithm: ?string, providers: array<string, array{type: string, connection: ?string, table: ?string, identifier_column: ?string, password_column: ?string}>, firewalls: array<string, array{pattern: string, stateless: bool, sessionless: bool, entry_point: ?string, provider: ?string, authenticators: array<int, string>}>} $config The canonical config array, matching the shape returned by toCanonicalArray().
	 * @param      ?string $sourceRef Origin reference for the compiled cache file's header comment.
	 * @return     string Compiled PHP code, exactly as execute() returns.
	 * @since      1.0.0
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = "return " . var_export($config, true) . ";";
		return $this->generate($code, $sourceRef);
	}

	/**
	 * @param      XmlConfigDomElement $provider The `<provider>` element to parse.
	 * @return     array{type: string, connection: ?string, table: ?string, identifier_column: ?string, password_column: ?string}
	 * @since      1.0.0
	 */
	private function parseProvider(XmlConfigDomElement $provider): array
	{
		return [
			'type' => $provider->getAttribute('type') ?? 'in_memory',
			'connection' => $provider->getAttribute('connection'),
			'table' => $provider->getAttribute('table'),
			'identifier_column' => $provider->getAttribute('identifier-column'),
			'password_column' => $provider->getAttribute('password-column'),
		];
	}

	/**
	 * @param      XmlConfigDomElement $firewall The `<firewall>` element to parse.
	 * @return     array{pattern: string, stateless: bool, sessionless: bool, entry_point: ?string, provider: ?string, authenticators: array<int, string>}
	 * @since      1.0.0
	 */
	private function parseFirewall(XmlConfigDomElement $firewall): array
	{
		$authenticators = [];
		foreach($firewall->get('authenticator') as $authenticator) {
			$ref = $authenticator->getAttribute('ref');
			if($ref !== null && $ref !== '') {
				$authenticators[] = $ref;
			}
		}

		return [
			'pattern' => $firewall->getAttribute('pattern') ?? '^/',
			'stateless' => $this->parseBool($firewall->getAttribute('stateless')),
			'sessionless' => $this->parseBool($firewall->getAttribute('sessionless')),
			'entry_point' => $firewall->getAttribute('entry-point'),
			'provider' => $firewall->getAttribute('provider'),
			'authenticators' => $authenticators,
		];
	}

	/**
	 * @param      ?string $value The raw attribute value (e.g. `"true"`, `"1"`, `null`).
	 * @return     bool True if $value represents a truthy boolean, otherwise false.
	 * @since      1.0.0
	 */
	private function parseBool(?string $value): bool
	{
		return $value === 'true' || $value === '1';
	}

	/**
	 * @param      XmlConfigDomElement $element The element to read the attribute from.
	 * @param      string $name The required attribute name.
	 * @param      ?string $sourceRef The config file path, used for error reporting.
	 * @return     string The attribute's value.
	 * @throws     ParseException If $element is missing the $name attribute (or it is empty).
	 * @since      1.0.0
	 */
	private function requireAttribute(XmlConfigDomElement $element, string $name, ?string $sourceRef): string
	{
		$value = $element->getAttribute($name);
		if($value === null || $value === '') {
			throw new ParseException(sprintf(
				'Configuration file "%s" has a <%s> element missing its required "%s" attribute',
				$sourceRef ?? '(unknown)',
				$element->localName,
				$name,
			));
		}
		return $value;
	}

	/**
	 * @param      XmlConfigDomDocument $document The parsed `security.xml` document.
	 * @param      ElementPositionIndex $positions Correlates surviving elements back to their source file/line.
	 * @return array{data: array{password_hasher_algorithm: ?string, providers: array<string, array{type: string, connection: ?string, table: ?string, identifier_column: ?string, password_column: ?string}>, firewalls: array<string, array{pattern: string, stateless: bool, sessionless: bool, entry_point: ?string, provider: ?string, authenticators: array<int, string>}>}, positions: array<string, array{file: string, line: int}>}
	 * @since      1.0.0
	 */
	public function toCanonicalArrayWithPositions(XmlConfigDomDocument $document, ElementPositionIndex $positions): array
	{
		$data = $this->toCanonicalArray($document);
		$elementPositions = [];

		$document->setDefaultNamespace(self::XML_NAMESPACE, 'security');
		foreach($document->getConfigurationElements() as $cfg) {
			if($cfg->has('firewalls')) {
				foreach($cfg->get('firewalls') as $firewall) {
					$name = $firewall->getAttribute('name');
					$position = $positions->forElement($firewall);
					if($name !== null && $position !== null) {
						$elementPositions["firewalls.{$name}"] = $position;
					}
				}
			}
			if($cfg->has('providers')) {
				foreach($cfg->get('providers') as $provider) {
					$name = $provider->getAttribute('name');
					$position = $positions->forElement($provider);
					if($name !== null && $position !== null) {
						$elementPositions["providers.{$name}"] = $position;
					}
				}
			}
		}

		return ['data' => $data, 'positions' => $elementPositions];
	}
}
