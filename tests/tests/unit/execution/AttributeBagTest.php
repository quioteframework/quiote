<?php
use PHPUnit\Framework\TestCase;
use Quiote\Execution\AttributeBag;

class AttributeBagTest extends TestCase
{
    public function testBasicGetHasAll()
    {
        $bag = new AttributeBag(['a'=>1,'b'=>null]);
        $this->assertTrue($bag->has('a'));
        $this->assertTrue($bag->has('b')); // array_key_exists true even if null
        $this->assertFalse($bag->has('z'));
        $this->assertSame(1, $bag->get('a'));
        $this->assertNull($bag->get('b'));
        $this->assertSame('def', $bag->get('z','def'));
        $this->assertEquals(['a'=>1,'b'=>null], $bag->all());
    }

    public function testImmutabilityWithWithoutMerge()
    {
        $bag = new AttributeBag(['x'=>10]);
        $bag2 = $bag->with('y', 20);
        $this->assertNotSame($bag, $bag2);
        $this->assertFalse($bag->has('y'));
        $this->assertTrue($bag2->has('y'));
        $bag3 = $bag2->without('x');
        $this->assertTrue($bag2->has('x'));
        $this->assertFalse($bag3->has('x'));
        $bag4 = $bag3->merge(['k'=>1,'m'=>2]);
        $this->assertTrue($bag4->has('k'));
        $this->assertTrue($bag4->has('m'));
        // merging empty array returns same instance
        $bag5 = $bag4->merge([]);
        $this->assertSame($bag4, $bag5);
    }

    public function testArrayAccessMutationPath()
    {
        $bag = new AttributeBag(['start'=>1]);
        $bag['start'] = 2; // direct mutation allowed
        $this->assertSame(2, $bag['start']);
        $bag['added'] = 5;
        $this->assertTrue(isset($bag['added']));
        unset($bag['start']);
        $this->assertFalse(isset($bag['start']));
    }

    public function testIterationAndCount()
    {
        $bag = new AttributeBag(['a'=>1,'b'=>2,'c'=>3]);
        $found = [];
        foreach ($bag as $k=>$v) { $found[$k]=$v; }
        $this->assertEquals(['a'=>1,'b'=>2,'c'=>3], $found);
        $this->assertCount(3, $bag);
    }
}
