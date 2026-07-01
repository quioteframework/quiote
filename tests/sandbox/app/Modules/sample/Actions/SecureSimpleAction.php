<?php
use Quiote\Action\Action;
use Quiote\View\View;

if(!class_exists('sample_SecureSimpleAction')) {
    class sample_SecureSimpleAction extends Action
    {
        #[\Override]
        public function isSimple(): bool { return true; }
        #[\Override]
        public function isSecure(): bool { return true; }
        public function execute() { return View::NONE; }
    }
}
