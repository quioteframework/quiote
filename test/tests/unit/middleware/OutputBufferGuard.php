<?php
class OutputBufferGuard
{
    private int $level;
    public function __construct()
    { $this->level = ob_get_level(); }
    public function restore(): void
    {
        $trace = getenv('AGAVI_OB_TRACE');
        while(ob_get_level() > $this->level) {
            $before = ob_get_level();
            try {
                ob_end_clean();
                $after = ob_get_level();
                if($trace) { \Agavi\Middleware\FrameworkMiddlewarePipeline::addExternalTrace(['guard:close', $before.'->'.$after]); }
            } catch(\Throwable $e) {
                if($trace) { \Agavi\Middleware\FrameworkMiddlewarePipeline::addExternalTrace(['guard:error', ob_get_level(), $e->getMessage()]); }
                break;
            }
        }
        if($trace) { \Agavi\Middleware\FrameworkMiddlewarePipeline::addExternalTrace(['guard:final', ob_get_level()]); }
    }
}
