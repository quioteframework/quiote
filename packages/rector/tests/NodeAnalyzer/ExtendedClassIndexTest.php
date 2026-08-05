<?php

declare(strict_types=1);

namespace Quiote\Rector\Tests\NodeAnalyzer;

use PHPUnit\Framework\TestCase;
use Quiote\Rector\NodeAnalyzer\ExtendedClassIndex;

/**
 * The guard that keeps a constructor out of a class other classes extend. Tested directly rather than
 * through a rule fixture: the index scans `.php` files, and a Rector fixture is a `.php.inc`, so no
 * arrangement of fixtures can make one class look like another's parent.
 */
final class ExtendedClassIndexTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $root = tempnam(sys_get_temp_dir(), 'eci_');
        self::assertNotFalse($root);
        unlink($root);
        mkdir($root . '/nested', 0777, true);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        foreach (['/nested/Child.php', '/Leaf.php', '/Base.php', '/notes.txt'] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }
        @rmdir($this->root . '/nested');
        @rmdir($this->root);
        parent::tearDown();
    }

    private function write(string $relativePath, string $contents): void
    {
        file_put_contents($this->root . $relativePath, $contents);
    }

    public function testAClassSomethingExtendsIsReported(): void
    {
        $this->write('/Base.php', "<?php\nclass Base {}\n");
        $this->write('/nested/Child.php', "<?php\nclass Child extends Base {}\n");

        $index = new ExtendedClassIndex([$this->root]);

        $this->assertTrue($index->isExtended('Base'), 'a subclass exists, in a nested directory');
        $this->assertFalse($index->isExtended('Child'), 'nothing extends the leaf');
    }

    /**
     * The question is asked with the fully-qualified name the rule has, and answered on the short name
     * a subclass writes -- which is the whole approximation. It errs toward "extended", and the cost of
     * that is declining a rewrite rather than breaking one.
     */
    public function testTheFullyQualifiedNameIsMatchedOnItsShortName(): void
    {
        $this->write('/Base.php', "<?php\nnamespace App;\nclass Base {}\n");
        $this->write('/nested/Child.php', "<?php\nnamespace App\\Deep;\nuse App\\Base;\nclass Child extends Base {}\n");

        $index = new ExtendedClassIndex([$this->root]);

        $this->assertTrue($index->isExtended('App\\Base'));
        $this->assertTrue($index->isExtended('Somewhere\\Else\\Base'), 'same short name: declined, deliberately');
    }

    public function testAFullyQualifiedParentIsRecordedByItsShortName(): void
    {
        $this->write('/nested/Child.php', "<?php\nclass Child extends \\Vendor\\Package\\Base {}\n");

        $index = new ExtendedClassIndex([$this->root]);

        $this->assertTrue($index->isExtended('Vendor\\Package\\Base'));
    }

    public function testNothingIsReportedForAnEmptyOrMissingRoot(): void
    {
        $this->write('/notes.txt', 'class Child extends Base {}');

        $index = new ExtendedClassIndex([$this->root, $this->root . '/does-not-exist']);

        $this->assertFalse($index->isExtended('Base'), 'only .php files are read');
    }

    public function testAnInterfaceOrEnumExtendsIsIndexedToo(): void
    {
        // Over-collecting here is harmless: the rules only ask about classes they were going to
        // rewrite, and a name that is only ever an interface parent is never one of them.
        $this->write('/Base.php', "<?php\ninterface Contract {}\ninterface Wider extends Contract {}\n");

        $index = new ExtendedClassIndex([$this->root]);

        $this->assertTrue($index->isExtended('Contract'));
    }
}
