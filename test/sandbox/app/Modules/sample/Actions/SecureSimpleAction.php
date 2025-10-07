<?php
use Agavi\Action\AgaviAction;
use Agavi\View\AgaviView;

if(!class_exists('sample_SecureSimpleAction')) {
    class sample_SecureSimpleAction extends AgaviAction
    {
        public function isSimple(): bool { return true; }
        public function isSecure(): bool { return true; }
        public function execute() { return AgaviView::NONE; }
    }
}
