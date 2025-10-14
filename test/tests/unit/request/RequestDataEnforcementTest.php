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

    public function testValidatorBaseWhitelistsRootAlias(): void
    {
        $req = new AgaviWebRequest();
        $req->enforceValidatedParameters(['items[]']);
        $this->assertNull($req->getParameter('items'));
        $this->assertNull($req->getParameter('items[]'));
        $this->expectException(AgaviUnvalidatedParameterAccessException::class);
        $req->getParameter('missing');
    }

    public function testNestedValidatorPathWhitelistsArrayAlias(): void
    {
        $req = new AgaviWebRequest();
        $req->enforceValidatedParameters(['cart[][amount]']);
        $this->assertNull($req->getParameter('cart'));
        $this->assertNull($req->getParameter('cart[]'));
    }
}
