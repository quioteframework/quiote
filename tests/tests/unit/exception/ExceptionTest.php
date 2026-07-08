<?php

use Quiote\Testing\UnitTestCase;
use Quiote\Exception\QuioteException;
use PHPUnit\Framework\Attributes\DataProvider;

class ExceptionTest extends UnitTestCase
{
	public function testGetOriginalCodePreservesNonIntegerCode(): void
	{
		// The parent Exception constructor coerces non-int codes to 0, but
		// QuioteException keeps the original value accessible separately.
		$e = new QuioteException('message', 'CUSTOM_CODE');
		$this->assertSame('CUSTOM_CODE', $e->getOriginalCode());
		$this->assertSame(0, $e->getCode());
	}

	public function testGetOriginalCodeWithAnIntegerCode(): void
	{
		$e = new QuioteException('message', 42);
		$this->assertSame(42, $e->getOriginalCode());
		$this->assertSame(42, $e->getCode());
	}


	/** @return array<string, array{0: string}> */
	public static function highlightSnippets(): array
	{
		return [
			'ticket1240' => [
				'<?php
class Default_Admin_Widgets_MenuSuccessView extends AdsDefaultBaseView
{
	public function executeHtml(RequestDataHolder $rd)
	{
		$this->setupHtml($rd);
		ob_start();?>duda
 <?php
throw new Exception();
ob_end_clean();

	}
}
?>'
			],
			'empty' => [
				'',
			],
			'empty with newline' => [
				'
',
			],
			'template starting with PHP code' => [
				'
				<?php echo $tm->_("Ohai", "default"); ?>
				<div />
				<?php echo $tm->_("Ohai", "default"); ?>
				'
			],
			'template starting with HTML code' => [
				'
				<div />
				<?php echo $tm->_("Ohai", "default"); ?>
				'
			],
		];
	}
	
	#[DataProvider('highlightSnippets')]
	public function testHighlightStringProducesValidXml(string $code): void
	{
		$highlighted = QuioteException::highlightString($code);
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