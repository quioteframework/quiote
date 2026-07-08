<?php

use Quiote\Testing\PhpUnitTestCase;
use Quiote\Config\Config;
use Quiote\Config\Format\XmlFormatDriver;
use Quiote\Config\RbacDefinitionConfigHandler;

/**
 * rbac_definitions.xml has legacy-upgrade <transformation> stylesheets
 * configured by default, same story as Factory/Database/Module: positions
 * come back empty in the shipped default configuration, and real once
 * transformations are skipped.
 */
class RbacDefinitionConfigHandlerPositionTest extends PhpUnitTestCase
{
	private string $dir;
	private string $xsl;

	protected function setUp(): void
	{
		parent::setUp();
		$this->dir = tempnam(sys_get_temp_dir(), 'rbacp_');
		unlink($this->dir);
		mkdir($this->dir);
		$this->xsl = Config::getString('core.quiote_dir') . '/Config/xsl/rbac_definitions.xsl';
	}

	protected function tearDown(): void
	{
		foreach (glob($this->dir . '/*') ?: [] as $f) {
			unlink($f);
		}
		rmdir($this->dir);
		Config::remove('core.skip_config_transformations');
		parent::tearDown();
	}

	private function writeRolesXml(): string
	{
		$path = $this->dir . '/rbac_definitions.xml';
		file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<ae:configurations xmlns:ae="http://quiote.dev/quiote/config/global/envelope/1.1"
                    xmlns="http://quiote.dev/quiote/config/parts/rbac_definitions/1.1">
    <ae:configuration>
        <roles>
            <role name="admin">
                <permissions>
                    <permission>manage_users</permission>
                </permissions>
            </role>
        </roles>
    </ae:configuration>
</ae:configurations>
XML);
		return $path;
	}

	public function testPositionsAreEmptyWithTheDefaultShippedTransformations(): void
	{
		$path = $this->writeRolesXml();

		$handler = new RbacDefinitionConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertArrayHasKey('admin', $result['data']);
		$this->assertSame([], $result['positions']);
	}

	public function testPositionsAreRealOnceTransformationsAreSkipped(): void
	{
		Config::set('core.skip_config_transformations', true, true);
		$path = $this->writeRolesXml();

		$handler = new RbacDefinitionConfigHandler();
		$driver = new XmlFormatDriver($handler, [$this->xsl, $this->xsl]);
		$result = $driver->loadWithPositions($path, 'test');

		$this->assertSame($path, $result['positions']['admin.parent']['file']);
		$this->assertSame(6, $result['positions']['admin.parent']['line']);
		$this->assertSame(6, $result['positions']['admin.permissions']['line']);
	}
}
?>
