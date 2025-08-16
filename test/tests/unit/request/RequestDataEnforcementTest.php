<?php
use PHPUnit\Framework\TestCase;
use Agavi\Request\AgaviRequestDataHolder;
use Agavi\Exception\AgaviUnvalidatedParameterAccessException;

class RequestDataEnforcementTest extends TestCase
{
    public function testThrowsOnUnvalidatedAccess(): void
    {
        $rd = new AgaviRequestDataHolder();
        $rd->setParameter('foo', 'bar');
        $rd->setParameter('baz', 'qux');
        $rd->enforceValidatedParameters(['foo'], true);
        $this->assertSame('bar', $rd->getParameter('foo'));
        $this->expectException(AgaviUnvalidatedParameterAccessException::class);
        $rd->getParameter('baz');
    }

    public function testSilentModeReturnsDefault(): void
    {
        $rd = new AgaviRequestDataHolder();
        $rd->setParameter('a', 1);
        $rd->enforceValidatedParameters(['a'], false);
        $this->assertSame(1, $rd->getParameter('a'));
        $this->assertNull($rd->getParameter('b'));
        $this->assertFalse($rd->hasParameter('b'));
    }
}
