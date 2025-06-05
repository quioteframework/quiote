<?php

use Agavi\Testing\AgaviUnitTestCase;
use Agavi\Exception\AgaviException;
use PHPUnit\Framework\Attributes\DataProvider;

class AgaviExceptionTest extends AgaviUnitTestCase
{
	public static function highlightSnippets()
	{
		return array(
			'ticket1240' => array(
				'<?php
class Default_Admin_Widgets_MenuSuccessView extends AdsDefaultBaseView
{
	public function executeHtml(AgaviRequestDataHolder $rd)
	{
		$this->setupHtml($rd);
		ob_start();?>duda
 <?php
throw new Exception();
ob_end_clean();

	}
}
?>'
			),
			'empty' => array(
				'',
			),
			'empty with newline' => array(
				'
',
			),
			'template starting with PHP code' => array(
				'
				<?php echo $tm->_("Ohai", "default"); ?>
				<div />
				<?php echo $tm->_("Ohai", "default"); ?>
				'
			),
			'template starting with HTML code' => array(
				'
				<div />
				<?php echo $tm->_("Ohai", "default"); ?>
				'
			),
		);
	}
	
	#[DataProvider('highlightSnippets')]
	public function testHighlightStringProducesValidXml($code)
	{
		$highlighted = AgaviException::highlightString($code);
		$highlighted = "<ol>\n<li><code>" . implode("</code></li>\n<li><code>", $highlighted) . "</code></li>\n</ol>";

		$doc = new DOMDocument();

		$luie = libxml_use_internal_errors(true);
		$doc->loadXML($highlighted);
		$errors = libxml_get_errors();
		libxml_use_internal_errors($luie);
		
		// Debug output
		if (count($errors) > 0) {
			echo "\n--- DEBUG: XML ERRORS ---\n";
			echo "Highlighted content:\n" . $highlighted . "\n";
			echo "Errors:\n";
			foreach ($errors as $error) {
				echo "- Line {$error->line}: {$error->message}";
			}
			echo "--- END DEBUG ---\n";
		}
		
		$this->assertEquals(0, count($errors));
	}
}

?>