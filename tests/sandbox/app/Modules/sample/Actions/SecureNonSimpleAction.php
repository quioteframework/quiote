<?php
use Quiote\Action\Action;
use Quiote\View\View;

if(!class_exists('sample_SecureNonSimpleAction')) {
    class sample_SecureNonSimpleAction extends Action
    {
        #[\Override]
        public function isSimple(): bool { return false; }
        #[\Override]
        public function isSecure(): bool { return true; }
        public function executeRead() { return View::NONE; }
    }
}
