<?php
namespace Quiote\Util;

use RecursiveDirectoryIterator;
use RecursiveFilterIterator;
use RuntimeException;
use SplFileInfo;

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
	 * The decorated directory iterator, kept typed here because
	 * RecursiveFilterIterator::current() is declared as mixed by the
	 * Iterator interface it implements.
	 * @var          RecursiveDirectoryIterator
	 */
	private RecursiveDirectoryIterator $directoryIterator;

	/**
	 * Creates a new RecursiveDirectoryFilterIterator.
	 * @param        RecursiveDirectoryIterator $iterator the directory iterator to decorate
	 * @param        array<int, string> $includes the list of include patterns (regular expressions)
	 * @param        array<int, string> $excludes the list of exclude patterns (literal)
	 * @param        boolean $noDefaultExcludes whether to use the default exclude patterns.
	 */
	public function __construct(RecursiveDirectoryIterator $iterator, array $includes = [], array $excludes = [], $noDefaultExcludes = false)
	{
		$this->directoryIterator = $iterator;
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
		if($this->currentFileInfo()->isDir()) {
			return true;
		}
		foreach($this->includes as $pattern) {
			if(preg_match($pattern, $this->currentFileInfo()->getPathName())) {
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
		return in_array($this->currentFileInfo()->getFilename(), $this->excludes);
	}

	/**
	 * Returns the current item as a SplFileInfo instance.
	 * RecursiveDirectoryIterator::current() can also return a plain pathname
	 * string when constructed with the CURRENT_AS_PATHNAME flag; this class
	 * relies on the default flags, so anything else indicates misuse.
	 * @throws       RuntimeException when the current item isn't a SplFileInfo
	 */
	private function currentFileInfo(): SplFileInfo
	{
		$current = $this->directoryIterator->current();
		if(!$current instanceof SplFileInfo) {
			throw new RuntimeException('Expected the decorated RecursiveDirectoryIterator to yield SplFileInfo instances; got ' . get_debug_type($current) . '. Was it constructed with the CURRENT_AS_PATHNAME flag?');
		}
		return $current;
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