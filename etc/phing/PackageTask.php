<?php
class PackageTask extends Task
{
	private $baseDir;
	private $buildDir;
	
	public function setBaseDir($baseDir)
	{
		$this->baseDir = (string) $baseDir;
	}
	
	public function setBuildDir($buildDir)
	{
		$this->buildDir = (string) $buildDir;
	}
	
	public function main()
	{
		require_once('PEAR/PackageFileManager2.php');
		require_once('PEAR/Exception.php');
		PEAR::setErrorHandling(PEAR_ERROR_CALLBACK,'PEAR_ErrorToPEAR_Exception');
		if(!$this->baseDir || !file_exists($this->baseDir)) {
			throw new BuildException('Base directory is not defined or does not exist.');
		}
		
		if(!$this->buildDir || !file_exists($this->buildDir)) {
			throw new BuildException('Build directory is not defined or does not exist.');
		}
		
		set_time_limit(0);
		
		$this->log("Adding .keep files to empty directories", PROJECT_MSG_INFO);
		
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(realpath('samples')), RecursiveIteratorIterator::CHILD_FIRST) as $dir) {
			if($dir->isDir()) {
				foreach(new DirectoryIterator($dir->getPathname()) as $d) {
					if(!in_array($d->getFilename(), ['.', '..'])) {
						continue 2;
					}
				}
				touch($dir->getPathname() . '/.keep');
			}
		}
		
		$this->log("Building package contents in: {$this->dir}", PROJECT_MSG_INFO);
		
		$version = $this->project->getProperty('quiote.pear.version');
		$status = $this->project->getProperty('quiote.status');
		
		// Modify short description. Try to keep under 80 chars width
$shortDesc = <<<EOD
PHP5 MVC Application Framework
EOD;

		// Modify long description. Try to keep under 80 chars width
$longDesc = <<<EOD
Quiote is a full-featured MVC-style framework for PHP5 with a strong focus on structure, code reusability and flexibility.
EOD;

		$p2 = new PEAR_PackageFileManager2;
		$p2->setOptions([
			'filelistgenerator' => 'file',
			'outputdirectory' => $this->baseDir,
			'packagedirectory' => $this->buildDir,
			'baseinstalldir' => 'quiote',
			'ignore' => [
				'.svn/',
			],
			'addhiddenfiles' => true,
			'dir_roles' => [
				'/' => 'php',
				'bin' => 'script',
				'samples' => 'data',
			],
			'installexceptions' => [
				'bin/quiote-dist' => '/',
				'bin/quiote.bat-dist' => '/',
			],
			'exceptions' => [
				'API_CHANGELOG' => 'doc',
				'CHANGELOG' => 'doc',
				'COPYRIGHT' => 'doc',
				'INSTALL' => 'doc',
				'LICENSE' => 'doc',
				'LICENSE-QUIOTE' => 'doc',
				'LICENSE-ICU' => 'doc',
				'LICENSE-SCHEMATRON' => 'doc',
				'LICENSE-UNICODE_CLDR' => 'doc',
				'RELEASE_NOTES' => 'doc',
				'UPGRADING' => 'doc',
			],
		]);
		$p2->setPackageType('php');
		$p2->setPackage('quiote');
		$p2->addMaintainer('lead', 'david', 'David Zülke', 'david.zuelke@bitextender.com');
		$p2->addMaintainer('developer', 'dominik', 'Dominik del Bondio', 'dominik.del.bondio@bitextender.com');
		$p2->addMaintainer('developer', 'felix', 'Felix Gilcher', 'felix.gilcher@bitextender.com');
		$p2->addMaintainer('developer', 'impl', 'Noah Fontes', 'nfontes@cynigram.com');
		$p2->addMaintainer('developer', 'v-dogg', 'Veikko Mäkinen', 'mail@veikkomakinen.com');
		$p2->setChannel('pear.quiote.org');
		$p2->setReleaseVersion($version);
		$p2->setAPIVersion($version);
		$p2->setReleaseStability($status);
		$p2->setAPIStability($status);
		$p2->setSummary($shortDesc);
		$p2->setDescription($longDesc);
		$p2->setNotes("To see what's new, please refer to the RELEASE_NOTES. Also, the CHANGELOG contains a full list of changes.\n\nFor installation instructions, consult INSTALL. Information on how to migrate applications written using previous releases can be found in UPGRADING.");
		
		// this must be the most stupid syntax I've ever seen.
		$p2->addRelease();
		$p2->setOSInstallCondition('windows');
		$p2->addInstallAs('bin/quiote.bat-dist', 'quiote.bat');
		$p2->addIgnoreToRelease('bin/quiote-dist');
		
		// and the next release... very cool, eh? how utterly stupid is that
		$p2->addRelease();
		$p2->addInstallAs('bin/quiote-dist', 'quiote');
		$p2->addIgnoreToRelease('bin/quiote.bat-dist');
		
		$p2->addPackageDepWithChannel('required', 'phing', 'pear.phing.info', '2.4.0');
		$p2->addPackageDepWithChannel('optional', 'PHPUnit', 'pear.phpunit.de', '3.7.0');
		
		$p2->addConflictingPackageDepWithChannel('phing', 'pear.php.net');
		
		$p2->setPhpDep('5.2.0');
		
		$p2->addExtensionDep('required', 'dom');
		$p2->addExtensionDep('required', 'libxml');
		$p2->addExtensionDep('required', 'SPL');
		$p2->addExtensionDep('required', 'Reflection');
		$p2->addExtensionDep('required', 'pcre');
		$p2->addExtensionDep('optional', 'xsl');
		$p2->addExtensionDep('optional', 'tokenizer');
		$p2->addExtensionDep('optional', 'session');
		$p2->addExtensionDep('optional', 'xmlrpc');
		$p2->addExtensionDep('optional', 'PDO');
		$p2->addExtensionDep('optional', 'iconv');
		$p2->addExtensionDep('optional', 'gettext');
		
		$p2->setPearinstallerDep('1.4.0');
		
		$p2->setLicense('LGPL', 'http://www.gnu.org/copyleft/lesser.html');
		
		$p2->addReplacement('bin/quiote-dist', 'pear-config', '@PEAR-DIR@', 'php_dir');
		$p2->addReplacement('bin/quiote-dist', 'pear-config', '@PHP-BIN@', 'php_bin');
		$p2->addReplacement('bin/quiote.bat-dist', 'pear-config', '@PEAR-DIR@', 'php_dir');
		$p2->addReplacement('bin/quiote.bat-dist', 'pear-config', '@PHP-BIN@', 'php_bin');
		$p2->addReplacement('src/build/build.xml', 'pear-config', '@PEAR-DIR@', 'php_dir');
		$p2->generateContents();
		
		try {
			$p2->writePackageFile();
		} catch(PEAR_Exception $e) {
			$this->log("Oops! Caught PEAR Exception: ".$e->getMessage());
		}
	}
}

function PEAR_ErrorToPEAR_Exception($err)
{
	if($err->getCode()) {
		throw new PEAR_Exception($err->getMessage(), $err->getCode());
	}
	throw new PEAR_Exception($err->getMessage());
}

?>