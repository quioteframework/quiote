<?php
namespace Agavi\Security;

enum SecurityDecision: string {
    case ALLOW = 'allow';
    case FORWARD_LOGIN = 'login_forward';
    case FORWARD_SECURE = 'secure_forward';
}
