<?php
namespace Quiote\Config;

use Quiote\Config\Util\DOM\XmlConfigDomDocument;

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
	 * @throws     <b>UnreadableException</b> If a requested configuration
	 *                                             file does not exist or is not
	 *                                             readable.
	 * @throws     <b>ParseException</b> If a requested configuration file is
	 *                                        improperly formatted.
	 * @since      1.0.0
	 */
	public function execute(XmlConfigDomDocument $document): string
	{
		return $this->executeArray($this->toCanonicalArray($document), $document->documentURI);
	}

	public function toCanonicalArray(XmlConfigDomDocument $document): array
	{
		// set up our default namespace
		$document->setDefaultNamespace(self::XML_NAMESPACE, 'rbac_definitions');

		$data = [];

		foreach ($document->getConfigurationElements() as $cfg) {
			if (!$cfg->has('roles')) {
				continue;
			}

			$this->parseRoles($cfg->get('roles'), null, $data);
		}

		return $data;
	}

	public function executeArray(array $config, ?string $sourceRef = null): string
	{
		$code = "return " . var_export($config, true) . ";";
		return $this->generate($code, $sourceRef);
	}

	/**
	 * Parse a 'roles' node.
	 * @param      mixed  The "roles" node (element or node list)
	 * @param      string The name of the parent role, or null.
	 * @param      array  A reference to the output data array.
	 * @since      1.0.0
	 */
	protected function parseRoles($roles, $parent, &$data)
	{
		foreach ($roles as $role) {
			$name = $role->getAttribute('name');
			$entry = [];
			$entry['parent'] = $parent;
			$entry['permissions'] = [];
			if ($role->has('permissions')) {
				foreach ($role->get('permissions') as $permission) {
					$entry['permissions'][] = $permission->getValue();
				}
			}
			if ($role->has('roles')) {
				$this->parseRoles($role->get('roles'), $name, $data);
			}
			$data[$name] = $entry;
		}
	}
}

?>
