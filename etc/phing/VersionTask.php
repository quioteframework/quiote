<?php
class VersionTask extends Task
{
	public function main()
	{
		$quiotePath = realpath(getcwd() . '/src/quiote.php');
		
		if(!$quiotePath && !file_exists($quiotePath)) {
			throw new BuildException('Quiote not found.');
		}

		require_once($quiotePath);
		
		$this->project->setUserProperty('quiote.version', QuioteConfig::get('quiote.version'));
		$this->project->setUserProperty('quiote.pear.version', sprintf("%d.%d.%d%s", 
			QuioteConfig::get('quiote.major_version'), 
			QuioteConfig::get('quiote.minor_version'), 
			QuioteConfig::get('quiote.micro_version'), 
			QuioteConfig::has('quiote.status') ? QuioteConfig::get('quiote.status') : ''
		));
		
		$status = QuioteConfig::get('quiote.status');
		
		if($status == 'dev') {
			$status = 'devel';
		} elseif(str_contains($status, 'alpha')) {
			$status = 'alpha';
		} elseif(str_contains($status, 'beta')) {
			$status = 'beta';
		} elseif(str_contains($status, 'RC')) {
			$status = 'beta';
		} else {
			$status = 'stable';
		}
		
		$this->project->setUserProperty('quiote.status', $status);
	}
}

?>