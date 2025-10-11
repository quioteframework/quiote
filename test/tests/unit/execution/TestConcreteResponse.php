<?php

use Agavi\Response\AgaviResponse;
use Agavi\Controller\AgaviOutputType;

class TestConcreteResponse extends AgaviResponse
{
    protected $content = '';
    private $redirect = null;
    public function initialize($context = null, array $parameters = []) {}
    public function setRedirect($to) { $this->redirect = ['to' => $to]; }
    public function getRedirect() { return $this->redirect; }
    public function hasRedirect() { return $this->redirect !== null; }
    public function clearRedirect() { $this->redirect = null; }
    public function clear() { $this->clearContent(); $this->clearAttributes(); }
    public function send(?AgaviOutputType $outputType = null) {}
}

?>