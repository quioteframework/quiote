<?php
namespace Quiote\User;

use Quiote\Context;
use Quiote\Util\AttributeHolder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * User wraps a client session and provides accessor methods for user
 * attributes. It also makes storing and retrieving multiple page form data
 * rather easy by allowing user attributes to be stored in namespaces, which
 * help organize data.
 * @since      1.0.0
 * @version    1.0.0
 */
class User extends AttributeHolder implements ResetInterface
{
	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

	/**
	 * @var        string Storage namespace where user attributes are put.
	 */
	protected $storageNamespace = 'org.quiote.user.User';

	/**
	 * Retrieve the current application context.
	 * @return     Context An Context instance.
	 * @since      1.0.0
	 */
	public final function getContext()
	{
		return $this->context;
	}

	/**
	 * Retrieve the Storage namespace
	 * @return     string The Storage namespace
	 * @since      1.0.0
	 */
	public function getStorageNamespace()
	{
		return $this->storageNamespace;
	}

	/**
	 * Initialize this User.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @return     void
	 * @throws     \Quiote\Exception\InitializationException If an error occurs while
	 *                                                 initializing this User.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
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
	 * You'd usually try to auth from a cookie here etc.
	 * @return     void
	 * @since      1.0.0
	 */
	public function startup() {}

	/**
	 * Execute the shutdown procedure.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
		// write attributes to the storage, but do not clobber with an empty map
		$ns = $this->getDefaultNamespace();
		$hasNsData = array_key_exists($ns, $this->attributes)
			&& count($this->attributes[$ns]) > 0;
		try {
			$keys = [];
			if (isset($this->attributes[$ns])) {
				$keys = array_keys($this->attributes[$ns]);
			}
			$logger = \Quiote\Logging\Log::for($this);
			if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
				$logger->debug('[User.shutdown.debug] oid=' . spl_object_id($this) . ' ns=' . $ns . ' hasNsData=' . ($hasNsData ? 1 : 0) . ' keyCount=' . count($keys) . ' keysSample=' . json_encode(array_slice($keys, 0, 12)));
			}
		} catch (\Throwable) {
		}
		if (!$hasNsData) {
			try {
				$logger = \Quiote\Logging\Log::for($this);
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[User.shutdown] skip store (no data) for ' . $this->storageNamespace);
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
	 * @param ?array<int, string> $onlyKeys Optional whitelist of attribute keys to persist.
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
				$logger = \Quiote\Logging\Log::for($this);
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[User.persistAttributesImmediate] persisted ns=' . $ns . ' keys=' . ($onlyKeys ? json_encode($onlyKeys) : 'ALL'));
				}
			} catch (\Throwable) {
			}
		} catch (\Throwable $e) {
			try {
				$logger = \Quiote\Logging\Log::for($this);
				if ($logger->isEnabled(\Quiote\Logging\Level::Debug)) {
					$logger->debug('[User.persistAttributesImmediate] ERROR ' . $e->getMessage());
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
	 * Called by Context::getUser() fast-restore path when available.
	 */
	public function restoreContext(Context $context): void
	{
		$this->context = $context;
		// Ensure attribute array shape (it may already be fine)
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
		$this->storageNamespace = 'org.quiote.user.User';
	}
}
