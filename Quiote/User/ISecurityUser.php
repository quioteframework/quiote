<?php
namespace Quiote\User;
/**
 * SecurityUser provides advanced security manipulation methods.
 * @since      1.0.0
 * @version    1.0.0
 */
interface ISecurityUser
{
	/**
	 * Add a credential to this user.
	 * @param      mixed $credential Credential data.
	 * @return     void
	 * @since      1.0.0
	 */
	public function addCredential($credential);

	/**
	 * Clear all credentials associated with this user.
	 * @return     void
	 * @since      1.0.0
	 */
	public function clearCredentials();

	/**
	 * Indicates whether or not this user has a credential.
	 * @param      mixed $credential Credential data.
	 * @return     bool true, if this user has the credential, otherwise false.
	 * @since      1.0.0
	 */
	public function hasCredentials($credential);

	/**
	 * Indicates whether or not this user is authenticated.
	 * @return     bool true, if this user is authenticated, otherwise false.
	 * @since      1.0.0
	 */
	public function isAuthenticated();

	/**
	 * Remove a credential from this user.
	 * @param      mixed $credential Credential data.
	 * @return     void
	 * @since      1.0.0
	 */
	public function removeCredential($credential);

	/**
	 * Set the authenticated status of this user.
	 * @param      bool $authenticated A flag indicating the authenticated status of this user.
	 * @return     void
	 * @since      1.0.0
	 */
	public function setAuthenticated($authenticated);

}

?>