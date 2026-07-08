<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;
use Quiote\Config\Util\DOM\XmlConfigDomElement;
use Quiote\Exception\ParseException;

/**
 * RbacDefinitionConfigHandler handles RBAC role and permission definition
 * files.
 *
 * Migrated to IArrayConfigHandler (phase 2). Canonical schema is a flat
 * map, role name => entry, already
 * exactly what execute() built inline:
 *   ['role_name' => ['parent' => 'parent_role_name'|null, 'permissions' => ['perm1', 'perm2']]]
 * Nested <roles> in XML become entries with 'parent' set; a PHP/YAML file
 * writes that same flat map directly (there's no XML-specific nesting
 * concept left to represent once you're at this shape).
 * @since      1.0.0
 * @version    1.0.0
 */
class RbacDefinitionConfigHandler extends XmlConfigHandler implements IArrayConfigHandler
{
	const XML_NAMESPACE = 'http://quiote.dev/quiote/config/parts/rbac_definitions/1.1';

	/**
	 * @throws     \Quiote\Exception\UnreadableException If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     \Quiote\Exception\ParseException If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	/**
	 * @return array<string, array{parent: ?string, permissions: array<int, mixed>}>
	 */
	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'rbac_definitions');

		$data = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			if (!$cfg->has('roles')) {
				continue;
			}

			$this->parseRoles($cfg->get('roles'), null, $data, $document->documentURI);
		}

		return $data;
	}

	/**
	 * @param array<string, array{parent: ?string, permissions: array<int, mixed>}> $config
	 */
	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = "return " . var_export($config, true) . ";";
		return $this->generate($code, $sourceRef);
	}

	/**
	 * Parse a 'roles' node.
	 * @param      mixed  $roles The "roles" node (element or node list)
	 * @param      ?string $parent The name of the parent role, or null.
	 * @param      array<string, array{parent: ?string, permissions: array<int, mixed>}>  $data A reference to the output data array.
	 * @param      ?string $sourceRef The config file path, used for error reporting.
	 * @return     void
	 * @throws     ParseException If a <role> is missing its required "name" attribute.
	 * @since      1.0.0
	 */
	protected function parseRoles($roles, $parent, &$data, ?string $sourceRef = null): void
	{
		foreach ($roles as $role) {
			// registerNodeClass() guarantees element nodes are always
			// XmlConfigDomElement, never a vanilla DOMNode.
			/** @var XmlConfigDomElement $role */
			$name = $role->getAttribute('name');
			if ($name === null || $name === '') {
				throw new ParseException(sprintf(
					'Configuration file "%s" has a <role> element missing its required "name" attribute',
					$sourceRef ?? '(unknown)'
				));
			}
			$entry = [];
			$entry['parent'] = $parent;
			$entry['permissions'] = [];
			if ($role->has('permissions')) {
				foreach ($role->get('permissions') as $permission) {
					$entry['permissions'][] = $permission->getValue();
				}
			}
			if ($role->has('roles')) {
				$this->parseRoles($role->get('roles'), $name, $data, $sourceRef);
			}
			$data[$name] = $entry;
		}
	}
}

?>
