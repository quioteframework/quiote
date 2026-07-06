<?php
use PHPUnit\Framework\TestCase;
use Quiote\Request\WebRequest;
use Quiote\Exception\UnvalidatedParameterAccessException;

class RequestDataEnforcementTest extends TestCase
{
    public function testAlwaysThrowsOnUnvalidatedAccess(): void
    {
        $rd = new WebRequest();
        // Use withQueryParams (intrinsic/query storage) rather than setParameter (which goes to
        // runtimeParameters and auto-whitelists — bypassing enforcement intentionally for action code).
        $rd = $rd->withQueryParams(['foo' => 'bar', 'baz' => 'qux']);
        $rd = $rd->enforceValidatedParameters(['foo']);
        $this->assertTrue($rd->hasParameter('foo'));
        $this->assertSame('bar', $rd->getParameter('foo'));
        $this->assertFalse($rd->hasParameter('baz'), 'Unvalidated parameter should report absent');
        $this->expectException(UnvalidatedParameterAccessException::class);
        $rd->getParameter('baz');
    }

    public function testValidatorBaseWhitelistsRootAlias(): void
    {
        $req = new WebRequest();
        $req = $req->enforceValidatedParameters(['items[]']);
        $this->assertNull($req->getParameter('items'));
        $this->assertNull($req->getParameter('items[]'));
        $this->expectException(UnvalidatedParameterAccessException::class);
        $req->getParameter('missing');
    }

    public function testNestedValidatorPathWhitelistsArrayAlias(): void
    {
        $req = new WebRequest();
        $req = $req->enforceValidatedParameters(['cart[][amount]']);
        $this->assertNull($req->getParameter('cart'));
        $this->assertNull($req->getParameter('cart[]'));
    }
}
