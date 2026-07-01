<?php
namespace Quiote\Database\Doctrine;

use Quiote\Database\DoctrineDatabase;

/**
 * An event listener for DoctrineDatabase.
 * @since      1.0.0
 * @version    1.0.0
 */
class DoctrineDatabaseEventListener extends Doctrine_EventListener
{
	/**
	 * @var        DoctrineDatabase The database adapter instance.
	 */
	protected $database;
	
	/**
	 * Constructor, accepts the DoctrineDatabase instance to operate on.
	 * @param      DoctrineDatabase The corresponding database adapter.
	 * @since      1.0.0
	 */
	public function __construct(DoctrineDatabase $database)
	{
		$this->database = $database;
	}
	
	/**
	 * Return the DoctrineDatabase instance associated with this listener.
	 * @return     DoctrineDatabase
	 * @since      1.0.0
	 */
	public function getDatabase()
	{
		return $this->database;
	}
	
	/**
	 * Post-connect listener. Will set charset and run init queries if configured.
	 * @param      Doctrine_Event The Doctrine event object.
	 * @since      1.0.0
	 */
	public function postConnect(Doctrine_Event $event)
	{
		$database = $this->getDatabase();
		
		if($database->hasParameter('charset')) {
			$event->getInvoker()->setCharset($database->getParameter('charset'));
		}
		
		foreach((array)$database->getParameter('init_queries') as $query) {
			$event->getInvoker()->exec($query);
		}
	}
}

?>