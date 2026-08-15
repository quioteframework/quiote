<?php
namespace Sandbox\Modules\Method\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

class NoValHttpAction extends Action
{
    public static string $last = '';
    // Helper for tests to clear static tracking state
    public static function ensureReset(): void
    {
        self::$last = '';
    }
    #[\Override]
    public function isSimple(): bool
    {
        return false;
    }
    public function validatePost(WebRequest $rd): bool
    {
        $present = $rd->hasParameter('fail');
        if (!$present) {
            self::$last = 'validatePost:missing';
            // Should always return true if parameter list was stripped.
            return true;
        }
        $val = $rd->getParameter('fail');
        if (!is_scalar($val) && !$val instanceof \Stringable) {
            throw new \InvalidArgumentException('NoValHttpAction expects a stringable "fail" parameter.');
        }
        self::$last = 'validatePost:' . (string) $val;
        // Unexpected under strict mode with zero validators; return false to highlight the leak.
        return false;
    }
    #[\Override]
    public function validate(WebRequest $rd): bool
    {
        if (self::$last === '')
            self::$last = 'validate';
        return true;
    }
    public function handlePostError(WebRequest $rd): string
    {
        self::$last = 'handlePostError';
        return 'PostError';
    }
    #[\Override]
    public function handleError(WebRequest $rd): string
    {
        self::$last = 'handleError';
        return 'GenericError';
    }
    public function executePost(WebRequest $rd): string
    {
        self::$last = 'executePost';
        return 'Post';
    }
    public function execute(WebRequest $rd): string
    {
        self::$last = 'execute';
        return 'Generic';
    }
    #[\Override]
    public function getDefaultViewName(): string
    {
        return 'Generic';
    }
}
