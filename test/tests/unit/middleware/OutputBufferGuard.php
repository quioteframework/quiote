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
                if($trace) { /* tracing disabled: was FrameworkMiddlewarePipeline::addExternalTrace */ }
            } catch(\Throwable $e) {
                if($trace) { /* tracing disabled */ }
                break;
            }
        }
    if($trace) { /* tracing disabled */ }
    }
}
