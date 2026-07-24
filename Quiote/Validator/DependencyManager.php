<?php
namespace Quiote\Validator;

use Quiote\Exception\ValidatorException;
use Quiote\Util\VirtualArrayPath;
use Symfony\Contracts\Service\ResetInterface;

/**
 * DependencyManager handles the dependencies in the validation process
 * @since      1.0.0
 * @version    1.0.0
 */
class DependencyManager implements ResetInterface
{
	/**
	 * @var array<int|string, mixed> already provided tokens.
	 */
	protected $depData = [];

	/**
	 * Clears the dependency cache.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clear()
	{
		$this->depData = [];
	}
	
	/**
	 * Checks whether a list of dependencies is met.
	 * @param      array<int, mixed> $tokens The list of dependencies that have to meet.
	 * @param      VirtualArrayPath $base The base path to which all tokens are
	 *                                   appended.
	 * @return     bool all dependencies are met
	 * @since      1.0.0
	 */
	public function checkDependencies(array $tokens, VirtualArrayPath $base)
	{
		$currentParts = $base->getParts();
		foreach($tokens as $token) {
			if($currentParts && str_contains((string) $token, '%')) { 
				// the depends attribute contains sprintf syntax 
				$token = vsprintf($token, $currentParts); 
			}
			
			$path = new VirtualArrayPath($token);
			if(!$path->getValue($this->depData)) {
				return false;
			}
		}
		
		return true;
	}
	
	/**
	 * Puts a list of tokens into the dependency cache.
	 * @param      array<int, mixed> $tokens The list of new tokens.
	 * @param      VirtualArrayPath $base The base path to which all tokens are
	 *                                   appended.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addDependTokens(array $tokens, VirtualArrayPath $base)
	{
		$currentParts = $base->getParts();
		foreach($tokens as $token) {
			if($currentParts && str_contains((string) $token, '%')) { 
				// the depends attribute contains sprintf syntax 
				$token = vsprintf($token, $currentParts); 
			}
			
			$path = new VirtualArrayPath($token);
			$path->setValue($this->depData, true);
		}
	}
	
	/**
	 * Populate key references in an argument base string if necessary.
	 * Fills only empty bracket positions with an sprintf() offset placeholder.
	 * Example: foo[][bar][] as input will return foo[%2$s][bar][%4$s] as output.
	 * @param      string $string The argument base string.
	 * @return     string The argument base string with empty brackets filled with
	 *                    correct sprintf() position specifiers.
	 * @since      1.0.0
	 */
	public static function populateArgumentBaseKeyRefs($string)
	{
		$index = 1;
		$result = preg_replace_callback(
			'#\[([^\]]*)\]#',
			function($matches) use(&$index) {
				$index++; // always increment so static key parts are "skipped" properly
				return $matches[1] !== '' ? $matches[0] : '[%'.$index.'$s]'; // leave parts other than "[]" intact, else inject numeric accessor
			},
			(string) $string
		);
		if ($result === null) {
			throw new ValidatorException('Failed to populate argument base key references for "' . $string . '": ' . (preg_last_error_msg()));
		}
		return $result;
	}
	
	/**
	 * Returns the list of provided tokens from the dependency cache.
	 *
	 * @return     array<int|string, mixed> Provided tokens from the dependency cache.
	 *
	 * @since      1.0.0
	 */
	public function getDependTokens()
	{
		return $this->depData;
	}

	public function reset() : void
	{
		$this->clear();
	}
}

?>
