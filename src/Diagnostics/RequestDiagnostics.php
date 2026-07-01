<?php
namespace Agavi\Diagnostics;

use Agavi\Request\AgaviWebRequest;

/**
 * Lightweight static helper to record canonical AgaviWebRequest identity and lifecycle stages.
 * Enabled when the 'Agavi.Diagnostics.RequestDiagnostics' log category is at DEBUG level.
 */
final class RequestDiagnostics
{
    private static ?int $canonicalId = null;
    private static array $stages = [];
    private static bool $locked = false; // once locked, id mismatches always logged

    public static function note(string $stage, ?AgaviWebRequest $req): void
    {
        if(!\Agavi\Logging\Log::create('Agavi.Diagnostics.RequestDiagnostics')->isEnabled(\Agavi\Logging\Level::Debug)) { return; }
        try {
            $id = $req instanceof AgaviWebRequest ? spl_object_id($req) : 0;
            if(self::$canonicalId === null && $req instanceof AgaviWebRequest) {
                self::$canonicalId = $id;
            } elseif($id !== 0 && self::$canonicalId !== null && $id !== self::$canonicalId) {
                $msg = '[RequestDiagnostics] MISMATCH stage=' . $stage . ' canonical=' . self::$canonicalId . ' got=' . $id;
                \Agavi\Logging\Log::create('Agavi.Diagnostics.RequestDiagnostics')->debug($msg);
            }
            self::$stages[] = [$stage, $id, microtime(true)];
        } catch(\Throwable) {
            // swallow diagnostics errors
        }
    }

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
