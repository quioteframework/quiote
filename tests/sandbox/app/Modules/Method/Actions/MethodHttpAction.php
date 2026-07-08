<?php
namespace Sandbox\Modules\Method\Actions;

use Quiote\Action\Action;
use Quiote\Request\WebRequest;

class MethodHttpAction extends Action
{
    public static string $last = '';
    #[\Override]
    public function isSimple(): bool
    {
        return false;
    }
    public function validatePost(WebRequest $rd): bool
    {
        $present = $rd->hasParameter('fail');
        $val = $present ? $rd->getParameter('fail') : null;
        self::$last = 'validatePost:' . ($present ? (string) $val : 'missing');
        if (!$present) {
            return true;
        }
        return !((string) $val === '1');
    }
    #[\Override]
    public function validate(WebRequest $rd): bool
    {
        if (self::$last === '')
            self::$last = 'validate';
        return true;
    } // ensure validatePost can overwrite last
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

    // Helper for tests
    public static function ensureReset(): void
    {
        self::$last = '';
    }
}
