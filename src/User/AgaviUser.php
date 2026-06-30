<?php

// +---------------------------------------------------------------------------+
// | This file is part of the Agavi package.                                   |
// | Copyright (c) 2005-2011 the Agavi Project.                                |
// | Based on the Mojavi3 MVC Framework, Copyright (c) 2003-2005 Sean Kerr.    |
// |                                                                           |
// | For the full copyright and license information, please view the LICENSE   |
// | file that was distributed with this source code. You can also view the    |
// | LICENSE file online at http://www.agavi.org/LICENSE.txt                   |
// |   vi: set noexpandtab:                                                    |
// |   Local Variables:                                                        |
// |   indent-tabs-mode: t                                                     |
// |   End:                                                                    |
// +---------------------------------------------------------------------------+
namespace Agavi\User;

use Agavi\AgaviContext;
use Agavi\Logging\AgaviDebugLogger;
use Agavi\Util\AgaviAttributeHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * AgaviUser wraps a client session and provides accessor methods for user
 * attributes. It also makes storing and retrieving multiple page form data
 * rather easy by allowing user attributes to be stored in namespaces, which
 * help organize data.
 *
 * @package    agavi
 * @subpackage user
 *
 * @author     Sean Kerr <skerr@mojavi.org>
 * @copyright  Authors
 * @copyright  The Agavi Project
 *
 * @since      0.9.0
 *
 * @version    $Id$
 */
class AgaviUser extends AgaviAttributeHolder implements ResetInterface
{
	/**
	 * @var        AgaviContext An AgaviContext instance.
	 */
	protected $context = null;

	/**
	 * @var        string Storage namespace where user attributes are put.
	 */
	protected $storageNamespace = 'org.agavi.user.User';

	/**
	 * Retrieve the current application context.
	 *
	 * @return     AgaviContext An AgaviContext instance.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the Storage namespace
	 *
	 * @return     string The Storage namespace
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function getStorageNamespace()
	{
		return $this->storageNamespace;
	}

	/**
	 * Initialize this User.
	 *
	 * @param      AgaviContext An AgaviContext instance.
	 * @param      array        An associative array of initialization parameters.
	 *
	 * @throws     <b>AgaviInitializationException</b> If an error occurs while
	 *                                                 initializing this User.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.9.0
	 */
	public function initialize(AgaviContext $context, array $parameters = [])
	{
		$this->context = $context;

		if (isset($parameters['default_namespace'])) {
			$this->defaultNamespace = $parameters['default_namespace'];
		}

		if (isset($parameters['storage_namespace'])) {
			$this->storageNamespace = $parameters['storage_namespace'];
		}

		$this->setParameters($parameters);

		// read data from storage
		$this->attributes = $context->getStorage()->retrieve($this->storageNamespace);

		// Normalize legacy/malformed payloads: ensure attributes are keyed by default namespace
		if (is_array($this->attributes) && !array_key_exists($this->defaultNamespace, $this->attributes)) {
			$this->attributes = [$this->defaultNamespace => $this->attributes];
		}

		if ($this->attributes == null) {
			// initialize our attributes array
			$this->attributes = [];
		}
	}

	/**
	 * Startup the user.
	 *
	 * You'd usually try to auth from a cookie here etc.
	 *
	 * @author     David Zülke <dz@bitxtender.com>
	 * @since      0.11.0
	 */
	public function startup() {}

	/**
	 * Execute the shutdown procedure.
	 *
	 * @author     Sean Kerr <skerr@mojavi.org>
	 * @since      0.9.0
	 */
	public function shutdown()
	{
		// write attributes to the storage, but do not clobber with an empty map
		$ns = $this->getDefaultNamespace();
		$hasNsData = is_array($this->attributes)
			&& array_key_exists($ns, $this->attributes)
			&& is_array($this->attributes[$ns])
			&& count($this->attributes[$ns]) > 0;
		try {
			$keys = [];
			if (is_array($this->attributes) && isset($this->attributes[$ns]) && is_array($this->attributes[$ns])) {
				$keys = array_keys($this->attributes[$ns]);
			}
			if (\Agavi\Util\DebugFlags::$user) {
				AgaviDebugLogger::debug('[AgaviUser.shutdown.debug] oid=' . spl_object_id($this) . ' ns=' . $ns . ' hasNsData=' . ($hasNsData ? 1 : 0) . ' keyCount=' . count($keys) . ' keysSample=' . json_encode(array_slice($keys, 0, 12)));
			}
		} catch (\Throwable) {
		}
		if (!$hasNsData) {
			try {
				if (\Agavi\Util\DebugFlags::$user) {
					AgaviDebugLogger::debug('[AgaviUser.shutdown] skip store (no data) for ' . $this->storageNamespace);
				}
			} catch (\Throwable) {
			}
			return;
		}
		$this->getContext()->getStorage()->store($this->storageNamespace, $this->attributes);
	}

	/**
	 * Immediately persist current user attributes (or a filtered subset) to storage.
	 * This reduces the window where a FrankenPHP worker could recreate a fresh
	 * user object (due to lazy getUser() calls) before shutdown() runs, which would
	 * otherwise lose in-memory identity attributes (userId/companyId etc.).
	 *
	 * @param array|null $onlyKeys Optional whitelist of attribute keys to persist.
	 */
	public function persistAttributesImmediate(?array $onlyKeys = null): void
	{
		try {
			$storage = $this->getContext()->getStorage();
			// Start from existing persisted structure (namespaced attributes map)
			$data = $storage->retrieve($this->storageNamespace);
			if (!is_array($data)) {
				$data = [];
			}

			$ns = $this->getDefaultNamespace();
			if (!isset($data[$ns]) || !is_array($data[$ns])) {
				$data[$ns] = [];
			}

			if ($onlyKeys !== null) {
				// Update only the selected keys within the default namespace
				foreach ($onlyKeys as $k) {
					if ($this->hasAttribute($k, $ns)) {
						$data[$ns][$k] = $this->getAttribute($k, $ns);
					}
				}
			} else {
				// Full replace with our current attributes map
				$data = $this->attributes;
			}

			$storage->store($this->storageNamespace, $data);
			if (method_exists($storage, 'flush')) {
				$storage->flush();
			}
			try {
				if (\Agavi\Util\DebugFlags::$user) {
					AgaviDebugLogger::debug('[AgaviUser.persistAttributesImmediate] persisted ns=' . $ns . ' keys=' . ($onlyKeys ? json_encode($onlyKeys) : 'ALL'));
				}
			} catch (\Throwable) {
			}
		} catch (\Throwable $e) {
			try {
				if (\Agavi\Util\DebugFlags::$user) {
					AgaviDebugLogger::debug('[AgaviUser.persistAttributesImmediate] ERROR ' . $e->getMessage());
				}
			} catch (\Throwable) {
			}				
		}
	}

	public function __sleep(): array
	{
		return ['attributes', 'storageNamespace', 'defaultNamespace', 'parameters'];
	}

	/**
	 * Re-bind context after unserialization without re-running full initialize logic.
	 * Called by AgaviContext::getUser() fast-restore path when available.
	 */
	public function restoreContext(AgaviContext $context): void
	{
		$this->context = $context;
		// Ensure attribute array shape (it may already be fine)
		if (!is_array($this->attributes)) {
			$this->attributes = [];
		}
		if (!array_key_exists($this->defaultNamespace, $this->attributes)) {
			$this->attributes = [$this->defaultNamespace => $this->attributes];
		}
	}

	#[\Override]
    public function reset(): void
	{
		$this->context = null;
		$this->parameters = [];
		$this->attributes = [];
		$this->storageNamespace = 'org.agavi.user.User';
	}
}
