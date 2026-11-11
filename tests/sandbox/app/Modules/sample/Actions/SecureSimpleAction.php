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
        /** A simple action never runs execute(), so it renders this view directly: none. */
        #[\Override]
        public function getDefaultViewName(): null { return View::NONE; }
        public function execute(): null { return View::NONE; }
    }
}
