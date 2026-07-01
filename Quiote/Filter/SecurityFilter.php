<?php
namespace Quiote\Filter;

/**
 * BasicSecurityFilter checks security by calling the getCredentials() 
 * method of the action. Once the credential has been acquired, 
 * BasicSecurityFilter verifies the user has the same credential 
 * by calling the hasCredentials() method of SecurityUser.
 * @since      1.0.0
 * @version    1.0.0
 */
// Legacy security filter removed (middleware handles auth/authorization). Left as empty stub for BC.
final class SecurityFilter {}