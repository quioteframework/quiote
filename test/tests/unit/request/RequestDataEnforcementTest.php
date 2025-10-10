<?php
use PHPUnit\Framework\TestCase;
use Agavi\Request\AgaviWebRequest;
use Agavi\Exception\AgaviUnvalidatedParameterAccessException;

class RequestDataEnforcementTest extends TestCase
{
    public function testAlwaysThrowsOnUnvalidatedAccess(): void
    {
        $rd = new AgaviWebRequest();
        $rd->setParameter('foo', 'bar');
        $rd->setParameter('baz', 'qux');
        $rd->enforceValidatedParameters(['foo']);
        $this->assertTrue($rd->hasParameter('foo'));
        $this->assertSame('bar', $rd->getParameter('foo'));
        $this->assertFalse($rd->hasParameter('baz'), 'Unvalidated parameter should report absent');
        $this->expectException(AgaviUnvalidatedParameterAccessException::class);
        $rd->getParameter('baz');
    }
}
