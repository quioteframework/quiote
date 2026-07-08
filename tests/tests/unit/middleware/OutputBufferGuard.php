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
            ob_end_clean();
            if($trace) { /* tracing disabled: was FrameworkMiddlewarePipeline::addExternalTrace */ }
        }
    if($trace) { /* tracing disabled */ }
    }
}
