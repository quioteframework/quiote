<?php
use Agavi\View\AgaviView;
use Agavi\Request\AgaviWebRequest;

if(!class_exists('sample_SecureNonSimpleSuccessView')) {
    class sample_SecureNonSimpleSuccessView extends AgaviView
    {
        public function execute(AgaviWebRequest $request): mixed { return ''; }
        public function executeHtml(AgaviWebRequest $request): string { return 'SNSV'; }
    }
}
