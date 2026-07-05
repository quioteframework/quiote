<?php
namespace Quiote\Storage;

/**
 * Storage allows you to customize the way Quiote stores its persistent 
 * data.
 * @since      1.0.0
 * @version    1.0.0
 */

use Quiote\Context;
use Quiote\Exception\InitializationException;
use Quiote\Exception\StorageException;
use Quiote\Util\ParameterHolder;
use Symfony\Contracts\Service\ResetInterface;

abstract class Storage extends ParameterHolder implements ResetInterface
{
	/**
	 * @var        ?Context An Context instance.
	 */
	protected $context = null;

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
	 * Initialize this Storage.
	 * @param      Context $context An Context instance.
	 * @param      array<string, mixed> $parameters An associative array of initialization parameters.
	 * @return     void
	 * @throws     InitializationException If an error occurs while
	 *                                                 initializing this Storage.
	 * @since      1.0.0
	 */
	public function initialize(Context $context, array $parameters = [])
	{
		$this->context = $context;

		$this->setParameters($parameters);
	}

	/**
	 * Executes code necessary to startup the storage (a session, for example).
	 * This code cannot be run in initialize(), because initialization has to
	 * finish completely, for all instances, before a session can be created.
	 * @return     void
	 * @since      1.0.0
	 */
	public function startup()
	{
	}

	/**
	 * Read data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     string|false Data associated with the key, or false if none.
	 * @throws     StorageException If an error occurs while reading
	 *                                          data from this storage.
	 * @since      1.0.0
	 */
	abstract function read(string $key) : string|false;

	/**
	 * Remove data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     mixed Data associated with the key.
	 * @throws     StorageException If an error occurs while removing
	 *                                          data from this storage.
	 * @since      1.0.0
	 */
	abstract function remove($key);

	/**
	 * Retrieve data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     mixed Data associated with the key.
	 * @throws     \Quiote\Exception\StorageException If an error occurs while retrieving
	 *                                          data from this storage.
	 * @since      1.0.0
	 */
	abstract function retrieve($key);

	/**
	 * Execute the shutdown procedure.
	 * @throws     StorageException If an error occurs while shutting
	 *                                          down this storage.
	 * @return     void
	 * @since      1.0.0
	 */
	abstract function shutdown();

	/**
	 * Write data to this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $id A unique key identifying your data.
	 * @param      mixed  $data Data associated with your key.
	 * @throws     StorageException If an error occurs while writing
	 *                                          to this storage.
	 * @since      1.0.0
	 */
	abstract function store(string $id, mixed $data): bool;

	#[\Override]
    public function reset(): void
	{
		$this->context = null;
		$this->parameters = [];
	}
}

?>