<?php
use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;

if(!class_exists('sample_SecureNonSimpleAction')) {
    class sample_SecureNonSimpleAction extends AgaviAction
    {
        public function isSimple(): bool { return false; }
        public function isSecure(): bool { return true; }
        public function executeRead() { return AgaviView::NONE; }
    }
}
