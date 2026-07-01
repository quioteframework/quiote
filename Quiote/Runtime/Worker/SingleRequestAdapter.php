<?php
namespace Quiote\Runtime\Worker;

class SingleRequestAdapter implements WorkerAdapterInterface
{
    public function run(callable $handle, ?callable $reset = null): void
    {
        $handle();
        // no reset required for single-shot usage
    }
}
