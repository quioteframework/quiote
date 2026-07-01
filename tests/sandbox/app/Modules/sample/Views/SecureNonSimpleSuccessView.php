<?php
use Quiote\View\View;
use Quiote\Request\WebRequest;

if(!class_exists('sample_SecureNonSimpleSuccessView')) {
    class sample_SecureNonSimpleSuccessView extends View
    {
        public function execute(WebRequest $request): mixed { return ''; }
        public function executeHtml(WebRequest $request): string { return 'SNSV'; }
    }
}
