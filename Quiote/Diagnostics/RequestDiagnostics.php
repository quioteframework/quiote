<?php
namespace Quiote\Diagnostics;

use Quiote\Request\WebRequest;

/**
 * Lightweight static helper to record canonical WebRequest identity and lifecycle stages.
 * Enabled when the 'Quiote.Diagnostics.RequestDiagnostics' log category is at DEBUG level.
 */
final class RequestDiagnostics
{
    private static ?int $canonicalId = null;
    /** @var array<int, array{0: string, 1: int, 2: float}> */
    private static array $stages = [];

    public static function note(string $stage, ?WebRequest $req): void
    {
        if(!\Quiote\Logging\Log::create('Quiote.Diagnostics.RequestDiagnostics')->isEnabled(\Quiote\Logging\Level::Debug)) { return; }
        try {
            $id = $req instanceof WebRequest ? spl_object_id($req) : 0;
            if(self::$canonicalId === null && $req instanceof WebRequest) {
                self::$canonicalId = $id;
            } elseif($id !== 0 && $id !== self::$canonicalId) {
                $msg = '[RequestDiagnostics] MISMATCH stage=' . $stage . ' canonical=' . self::$canonicalId . ' got=' . $id;
                \Quiote\Logging\Log::create('Quiote.Diagnostics.RequestDiagnostics')->debug($msg);
            }
            self::$stages[] = [$stage, $id, microtime(true)];
        } catch(\Throwable) {
            // swallow diagnostics errors
        }
    }

    /**
     * @return array{canonical: ?int, stages: array<int, array{0: string, 1: int, 2: float}>}
     */
    public static function summary(): array
    {
        return [
            'canonical' => self::$canonicalId,
            'stages' => self::$stages,
        ];
    }

    public static function reset(): void
    {
        self::$canonicalId = null;
        self::$stages = [];
    }
}
