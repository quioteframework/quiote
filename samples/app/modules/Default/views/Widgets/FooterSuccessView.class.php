<?php
class Default_Widgets_FooterSuccessView extends SampleAppDefaultBaseView
{

	public function executeHtml(RequestDataHolder $rd)
	{
		// will automatically load "slot" layout for us
		$this->setupHtml($rd);
		
		$this->setAttribute('locales', $this->tm->getAvailableLocales());
		$this->setAttribute('current_locale', $this->tm->getCurrentLocaleIdentifier());
		$this->setAttribute('quiote_plug', Config::get('quiote.release'));
	}

}

?>