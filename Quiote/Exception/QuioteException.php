<?php
namespace Quiote\Exception;
/**
 * QuioteException is the base class for all Quiote related exceptions.
 *
 * Rendering an exception to a client belongs to {@see \Quiote\Exception\Rendering\ExceptionRenderer}
 * and its registry, not here: the default {@see \Quiote\Exception\Rendering\SafeRenderer} reveals
 * nothing about the exception, and the developer-facing page comes from the opt-in
 * `quioteframework/whoops` package, which brings its own stack-frame and source rendering.
 *
 * @since      1.0.0
 * @version    1.0.0
 */
class QuioteException extends \Exception
{
	public function __construct(string $message = '', private readonly int|string $mixedCode = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, is_int($this->mixedCode) ? $this->mixedCode : 0, $previous);
	}

	/** Returns the original code, which may be a string (e.g. a PDO SQLSTATE like "42P01"). */
	public function getOriginalCode(): int|string
	{
		return $this->mixedCode;
	}
}
