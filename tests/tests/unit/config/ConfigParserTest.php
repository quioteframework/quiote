<?php

use Quiote\Config\ConfigParser;
use Quiote\Exception\ParseException;
use Quiote\Testing\PhpUnitTestCase;

/**
 * Happy + failure path coverage for the deprecated ConfigParser::parse(), in
 * particular the node-namespace filtering in parseNodes() -- it used to also
 * accept nodes in the legacy pre-1.1 Quiote envelope namespace, which no
 * longer exists, so it now only walks unnamespaced (foreign) XML nodes.
 */
class ConfigParserTest extends PhpUnitTestCase
{
    /** @var list<string> */
    private array $filesToDelete = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->filesToDelete as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    private function tempFile(string $contents): string
    {
        $path = sprintf('%s/config-parser-%s.xml', sys_get_temp_dir(), bin2hex(random_bytes(8)));
        file_put_contents($path, $contents);
        $this->filesToDelete[] = $path;
        return $path;
    }

    public function testParseWalksUnnamespacedForeignXmlNodes(): void
    {
        $file = $this->tempFile('<root attr="hello"><child>world</child></root>');

        $result = (new ConfigParser())->parse($file);

        $roots = $result->getChildren('root');
        $this->assertCount(1, $roots);
        $root = $roots[0];
        $this->assertSame('root', $root->getName());
        $this->assertSame('hello', $root->getAttribute('attr'));
        $children = $root->getChildren('child');
        $this->assertCount(1, $children);
        $this->assertSame('world', (string) $children[0]);
    }

    public function testParseThrowsClearParseExceptionForLegacyEnvelopeFile(): void
    {
        $file = $this->tempFile('<configurations xmlns="http://quiote.org/quiote/1.0/config"><configuration/></configurations>');

        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('unsupported legacy Quiote envelope namespace "http://quiote.org/quiote/1.0/config"');
        (new ConfigParser())->parse($file);
    }
}
