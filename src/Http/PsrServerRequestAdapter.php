<?php
namespace Agavi\Http;

/**
 * DEPRECATED: PsrServerRequestAdapter has been removed in favor of using AgaviWebRequest
 * directly (which now implements ServerRequestInterface). This stub remains only to avoid
 * autoload errors during transition. Any attempt to instantiate will throw.
 */
class PsrServerRequestAdapter
{
    public function __construct()
    {
        throw new \RuntimeException('PsrServerRequestAdapter removed; use Agavi\\Request\\AgaviWebRequest directly.');
    }
}
