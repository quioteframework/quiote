<?php
namespace Agavi\Execution;

enum SecurityDecision: string { case Allow = 'allow'; case LoginForward = 'login'; case SecureForward = 'secure'; }
