<?php
namespace Quiote\Http;

/**
 * DEPRECATED: PsrServerRequestAdapter has been removed in favor of using WebRequest
 * directly (which now implements ServerRequestInterface). This stub remains only to avoid
 * autoload errors during transition. Any attempt to instantiate will throw.
 */
class PsrServerRequestAdapter
{
    public function __construct()
    {
        throw new \RuntimeException('PsrServerRequestAdapter removed; use Quiote\\Request\\WebRequest directly.');
    }
}
