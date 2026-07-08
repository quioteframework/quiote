<?php
use Quiote\Config\ConfigCache;

/**
 * TestingConfigCache allows access to some internal config cache properties 
 * @since      1.0.0
 * @version    1.0.0
 */
class TestingConfigCache extends ConfigCache
{
	public static function handlersDirty(): bool
	{
		return self::$handlersDirty;
	}

	/** @return array<string,bool> */
	public static function getHandlerFiles(): array
	{
		return self::$handlerFiles;
	}

	/** @return ?array<string,array<string,mixed>> */
	public static function getHandlers(): ?array
	{
		return self::$handlers;
	}

	public static function resetHandlers(): void
	{
		self::$handlers = null;
	}

	/**
	 * Forget a previously-registered config handlers file so that a subsequent
	 * addConfigHandlersFile() for the same path is treated as new again.
	 * The handler-file registry ($handlerFiles) is process-wide static state.
	 * In a full test run other code (e.g. Controller loading a module's
	 * config_handlers.xml) may already have registered the same file, which would
	 * make addConfigHandlersFile() a no-op and leave the dirty flag unset. Tests
	 * that assert on that behaviour call this first to restore a known precondition.
	 */
	public static function forgetHandlerFile(string $filename): void
	{
		unset(self::$handlerFiles[$filename]);
	}

	#[\Override]
    public static function setupHandlers()
	{
		parent::setupHandlers();
	}

	#[\Override]
    public static function getHandlerInfo($name)
	{
		return parent::getHandlerInfo($name);
	}

	#[\Override]
    public static function callHandler($name, $config, $cache, $context, ?array $handlerInfo = null)
	{
		parent::callHandler($name, $config, $cache, $context, $handlerInfo);
	}
}


?>