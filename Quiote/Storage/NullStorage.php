<?php
namespace Quiote\Storage;

/**
 * NullStorage doesn't store what it is given and always returns null on
 * reads. Perfect if you want to use a User, but no sessions.
 * @since      1.0.0
 * @version    1.0.0
 */
class NullStorage extends Storage
{
	/**
	 * Read data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     false Always false.
	 * @since      1.0.0
	 */
	public function read(string $key) : string|false
	{
		return false;
	}

	/**
	 * Remove data from this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @return     null Always null.
	 * @since      1.0.0
	 */
	public function remove($key)
	{
		return null;
	}

	/**
	 * Execute the shutdown procedure.
	 * @return     void
	 * @since      1.0.0
	 */
	public function shutdown()
	{
	}

	/**
	 * Write data to this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @param      mixed  $data Data associated with your key.
	 * @return     void
	 * @since      1.0.0
	 */
	public function write($key, $data)
	{
	}

	/**
	 * Store data in this storage.
	 * The preferred format for a key is directory style so naming conflicts can
	 * be avoided.
	 * @param      string $key A unique key identifying your data.
	 * @param      mixed  $data Data associated with your key.
	 * @return     bool Always false.
	 */
	public function store($key, $data) : bool
	{
		// Null storage does not store anything.
		return false;
	}

	public function retrieve($key) {
		return false;
	}
}

?>