<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Config\Config;
use Quiote\Console\Command\Scaffold\GeneratorSupport;
use Quiote\Exception\ConfigurationException;

/**
 * GeneratorSupport holds the guards every `make:*` command runs before it
 * writes anything. Those commands are covered by subprocess CLI tests (see
 * QuioteCliProcessTrait), which cannot assert on the individual guard's
 * behaviour -- so the guards are pinned down directly here.
 */
final class GeneratorSupportTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/quiote-generator-support-' . uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ---------------------------------------------------------------
    // validateClassNameSegment()
    // ---------------------------------------------------------------

    /** @return list<array{string}> */
    public static function validNames(): array
    {
        return [['Post'], ['SendWelcomeEmail'], ['A'], ['Api2Client'], ['X9']];
    }

    #[DataProvider('validNames')]
    public function testAcceptsPascalCaseNames(string $name): void
    {
        $this->expectNotToPerformAssertions();
        GeneratorSupport::validateClassNameSegment($name);
    }

    /** @return list<array{string}> */
    public static function invalidNames(): array
    {
        return [
            ['post'],            // lowercase first letter
            ['not-valid'],       // hyphen
            ['Not_Valid'],       // underscore
            ['2Fast'],           // leading digit
            [''],                // empty
            ['With Space'],
            ['Namespaced\\Name'],
            ['Post.php'],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testRejectsAnythingThatIsNotAPascalCaseSegment(string $name): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('is not a valid name');
        GeneratorSupport::validateClassNameSegment($name);
    }

    // ---------------------------------------------------------------
    // guardOverwrite()
    // ---------------------------------------------------------------

    public function testGuardOverwritePassesWhenTheTargetDoesNotExist(): void
    {
        $this->expectNotToPerformAssertions();
        GeneratorSupport::guardOverwrite($this->tmpDir . '/absent.php', false);
    }

    public function testGuardOverwriteRejectsAnExistingFileWithoutForce(): void
    {
        $path = $this->tmpDir . '/existing.php';
        file_put_contents($path, 'original');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Use --force to overwrite it.');
        GeneratorSupport::guardOverwrite($path, false);
    }

    public function testGuardOverwriteAllowsAnExistingFileWithForce(): void
    {
        $path = $this->tmpDir . '/existing.php';
        file_put_contents($path, 'original');

        GeneratorSupport::guardOverwrite($path, true);

        $this->assertStringEqualsFile($path, 'original', 'the guard must not touch the file itself');
    }

    public function testGuardOverwriteIgnoresDirectories(): void
    {
        // Only files are guarded: `make:module` deliberately writes into an
        // existing module directory.
        $this->expectNotToPerformAssertions();
        GeneratorSupport::guardOverwrite($this->tmpDir, false);
    }

    // ---------------------------------------------------------------
    // Config accessors.
    // ---------------------------------------------------------------

    public function testAppDirComesFromConfig(): void
    {
        $this->assertSame(Config::getString('core.app_dir'), GeneratorSupport::appDir());
    }

    public function testAppNamespaceComesFromConfigWithoutLeadingSeparators(): void
    {
        $previous = Config::get('core.namespace_prefix');
        try {
            Config::set('core.namespace_prefix', '\\Demo\\App\\');
            $this->assertSame('Demo\\App', GeneratorSupport::appNamespace());
        } finally {
            Config::set('core.namespace_prefix', $previous);
        }
    }

    // ---------------------------------------------------------------
    // requireString()
    // ---------------------------------------------------------------

    public function testRequireStringPassesStringsThrough(): void
    {
        $this->assertSame('Post', GeneratorSupport::requireString('Post', 'name'));
        $this->assertSame('', GeneratorSupport::requireString('', 'name'));
    }

    /** @return list<array{mixed}> */
    public static function nonStrings(): array
    {
        return [[null], [42], [true], [['Post']], [1.5]];
    }

    #[DataProvider('nonStrings')]
    public function testRequireStringRejectsNonStrings(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('name must be a string.');
        GeneratorSupport::requireString($value, 'name');
    }

    // ---------------------------------------------------------------
    // writeFile()
    // ---------------------------------------------------------------

    public function testWriteFileCreatesMissingParentDirectories(): void
    {
        $path = $this->tmpDir . '/Modules/Blog/Actions/PostAction.php';

        GeneratorSupport::writeFile($path, '<?php // generated');

        $this->assertStringEqualsFile($path, '<?php // generated');
    }

    public function testWriteFileOverwritesAnExistingFile(): void
    {
        $path = $this->tmpDir . '/PostAction.php';
        file_put_contents($path, 'original');

        // The overwrite decision belongs to guardOverwrite(); writeFile() itself
        // is unconditional.
        GeneratorSupport::writeFile($path, 'replacement');

        $this->assertStringEqualsFile($path, 'replacement');
    }

    public function testWriteFileFailsWhenTheParentPathIsAFile(): void
    {
        $blocker = $this->tmpDir . '/blocker';
        file_put_contents($blocker, 'not a directory');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Could not create directory');
        GeneratorSupport::writeFile($blocker . '/Actions/PostAction.php', '<?php');
    }
}
