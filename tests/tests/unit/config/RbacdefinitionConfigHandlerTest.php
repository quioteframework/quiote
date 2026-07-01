<?php

use Quiote\Config\Config;
use Quiote\Config\RbacDefinitionConfigHandler;

require_once(__DIR__ . '/ConfigHandlerTestBase.php');

class RbacDefinitionConfigHandlerTest extends ConfigHandlerTestBase
{
	public function testHandler()
	{
		$document = $this->parseConfiguration(
			Config::get('core.config_dir') . '/tests/rbac_definitions.xml',
			Config::get('core.quiote_dir') . '/Config/xsl/rbac_definitions.xsl'
		);
		
		$handler = new RbacDefinitionConfigHandler();
		$cfg = $this->includeCode($handler->execute($document));
		
		$expected = [
			'administrator' => 
			 [
				'parent' => NULL,
				'permissions' => 
				 [
					'admin',
				],
			],
			'photographer' => 
			 [
				'parent' => 'member',
				'permissions' => 
				 [
					0 => 'photos.edit-own',
					1 => 'photos.add',
					2 => 'photos.lock',
				],
			],
			'photomoderator' => 
			 [
				'parent' => 'member',
				'permissions' => 
				 [
					0 => 'photos.edit',
					1 => 'photos.delete',
					2 => 'photos.unlock',
				],
			],
			'member' => 
			 [
				'parent' => 'guest',
				'permissions' => 
				 [
					0 => 'photos.comments.view',
					1 => 'photos.comments.add',
					2 => 'photos.rate',
					3 => 'lightbox',
					4 => 'tags.suggest',
				],
			],
			'guest' => 
			 [
				'parent' => NULL,
				'permissions' => 
				 [
					0 => 'photos.list',
					1 => 'photos.detail',
				],
			],
		];
		$this->assertEquals($expected, $cfg);
	}
}
