<?php
class OutputBufferGuard
{
    private readonly int $level;
    public function __construct()
    { $this->level = ob_get_level(); }
    public function restore(): void
    {
        $trace = getenv('QUIOTE_OB_TRACE');
        while(ob_get_level() > $this->level) {
            $before = ob_get_level();
            try {
                ob_end_clean();
                $after = ob_get_level();
                if($trace) { /* tracing disabled: was FrameworkMiddlewarePipeline::addExternalTrace */ }
            } catch(\Throwable) {
                if($trace) { /* tracing disabled */ }
                break;
            }
        }
    if($trace) { /* tracing disabled */ }
    }
}
