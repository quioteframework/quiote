<?php
namespace Quiote\Util;

use RecursiveDirectoryIterator;
use RecursiveFilterIterator;

/**
 * RecursiveDirectoryFilterIterator filters a RecursiveDirectoryIterator
 * with a given set of include and exclude patterns.
 * @since      1.0.0
 * @version    1.0.0
 */
class RecursiveDirectoryFilterIterator extends RecursiveFilterIterator
{
	/**
	 * The list of default excludes
	 * @var          array<int, string>
	 */
	public static $defaultExcludes = ['.', '..', '.svn', 'CVS', '_darcs', '.arch-params', '.monotone', '.bzr'];

	/**
	 * @var          array<int, string> the list of excludes
	 */
	protected $excludes = [];

	/**
	 * @var          array<int, string> the list of include patterns
	 */
	protected $includes = [];

	/**
	 * Creates a new RecursiveDirectoryFilterIterator.
	 * @param        RecursiveDirectoryIterator $iterator the directory iterator to decorate
	 * @param        array<int, string> $includes the list of include patterns (regular expressions)
	 * @param        array<int, string> $excludes the list of exclude patterns (literal)
	 * @param        boolean $noDefaultExcludes whether to use the default exclude patterns.
	 */
	public function __construct(RecursiveDirectoryIterator $iterator, array $includes = [], array $excludes = [], $noDefaultExcludes = false)
	{
		parent::__construct($iterator);
		if(!$noDefaultExcludes) {
			$this->excludes = array_merge($excludes, self::$defaultExcludes);
		} else {
			$this->excludes = $excludes;
		}
		
		foreach($includes as $pattern) {
			$this->includes[] = '!'.str_replace('!', '\!', $pattern).'!i';
		}
	}
	
	/**
	 * Checks whether the current item is included.
	 * An item is included if it is matched by any of the include expressions
	 * and none of the exclude patterns.
	 * @return       boolean true if the item is included
	 */
	public function accept(): bool
	{
		if(!$this->isIncluded()) {
			return false;
		}
		if($this->isExcluded()) {
			return false;
		}
		
		return true;
	}
	
	/**
	 * Checks whether the current item is matched by an include expression.
	 * Directories are always included.
	 * @return       boolean true if the items path matches an include expression
	 */
	protected function isIncluded() {
		if(empty($this->includes)) {
			return true;
		}
		if($this->current()->isDir()) {
			return true;
		}
		foreach($this->includes as $pattern) {
			if(preg_match($pattern, (string) $this->current()->getPathName())) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Checks whether the item is matched by any of the exclude expressions.
	 * @return       boolean true if the items name equals an exclude pattern.
	 */
	protected function isExcluded() {
		return in_array($this->current()->getFilename(), $this->excludes);
	}
	
	/**
	 * Returns a child iterator.
	 * @return       RecursiveDirectoryFilterIterator an iterator for a subdirectory
	 */
	public function getChildren(): ?RecursiveDirectoryFilterIterator
	{
		$it = parent::getChildren();
		// RecursiveFilterIterator's default getChildren() implementation uses
		// `new static(...)`, so it always returns an instance of this same
		// subclass, never a plain RecursiveFilterIterator.
		/** @var self $it */
		$it->excludes = $this->excludes;
		$it->includes = $this->includes;
		return $it;
	}
}

?>