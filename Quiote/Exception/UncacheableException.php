<?php
namespace Quiote\Exception;
/**
 * UncacheableException can be thrown by cache group callbacks to signal to
 * the framework's execution filter that no caching should occur.
 * @since      1.0.0
 * @version    1.0.0
 */
class UncacheableException extends QuioteException
{
}

?>