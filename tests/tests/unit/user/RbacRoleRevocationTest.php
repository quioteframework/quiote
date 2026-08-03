<?php

use Quiote\Testing\UnitTestCase;
use Quiote\User\RbacSecurityUser;

class RevocationRbacUser extends RbacSecurityUser
{
    #[\Override]
    protected function loadDefinitions()
    {
        $this->definitions = [
            'editor'   => ['permissions' => ['post.edit']],
            'reviewer' => ['permissions' => ['post.review']],
            // Shared permission: proves credentials are re-derived from the whole
            // surviving role set rather than subtracted role by role.
            'auditor'  => ['permissions' => ['post.review', 'post.audit']],
            'base'     => ['permissions' => ['login']],
            'manager'  => ['parent' => 'base', 'permissions' => ['team.view']],
        ];
    }
}

/**
 * Revoking one role must not disturb the permissions of the roles that remain.
 *
 * Credentials are derived from the whole role set, so revocation re-derives them
 * from the survivors. grantRole() bails out for a role already present in the
 * role list, so the list has to be emptied before re-granting -- otherwise every
 * surviving role stays held but silently loses its permissions.
 */
class RbacRoleRevocationTest extends UnitTestCase
{
    private RevocationRbacUser $user;

    #[\Override]
    public function setUp(): void
    {
        $this->user = new RevocationRbacUser();
        $this->user->initialize($this->getContext());
    }

    public function testRevokingOneRoleKeepsTheOtherRolesPermissions(): void
    {
        $this->user->grantRoles(['editor', 'reviewer']);
        $this->assertTrue($this->user->hasCredential('post.edit'));
        $this->assertTrue($this->user->hasCredential('post.review'));

        $this->user->revokeRole('editor');

        $this->assertFalse($this->user->hasRole('editor'));
        $this->assertTrue($this->user->hasRole('reviewer'));
        $this->assertFalse($this->user->hasCredential('post.edit'), 'the revoked role loses its permission');
        $this->assertTrue($this->user->hasCredential('post.review'), 'a retained role keeps its permission');
    }

    public function testAPermissionGrantedByTwoRolesSurvivesRevokingOneOfThem(): void
    {
        $this->user->grantRoles(['reviewer', 'auditor']);

        $this->user->revokeRole('reviewer');

        $this->assertTrue($this->user->hasRole('auditor'));
        $this->assertTrue(
            $this->user->hasCredential('post.review'),
            'auditor also grants post.review, so it must survive revoking reviewer',
        );
        $this->assertTrue($this->user->hasCredential('post.audit'));
    }

    public function testInheritedPermissionsAreReDerivedForSurvivingRoles(): void
    {
        $this->user->grantRoles(['editor', 'manager']);
        $this->assertTrue($this->user->hasCredential('login'), 'inherited from base via manager');

        $this->user->revokeRole('editor');

        $this->assertTrue($this->user->hasRole('manager'));
        $this->assertTrue($this->user->hasCredential('team.view'));
        $this->assertTrue($this->user->hasCredential('login'), 'the inherited permission must be re-derived too');
    }

    public function testRevokingTheLastRoleLeavesNoRolesOrCredentials(): void
    {
        $this->user->grantRole('editor');

        $this->user->revokeRole('editor');

        $this->assertSame([], $this->user->getRoles());
        $this->assertFalse($this->user->hasCredential('post.edit'));
        $this->assertTrue($this->user->isDirty(), 'the revocation must be persisted');
    }

    public function testRevokeAllRolesClearsEverything(): void
    {
        $this->user->grantRoles(['editor', 'reviewer', 'manager']);

        $this->user->revokeAllRoles();

        $this->assertSame([], $this->user->getRoles());
        $this->assertFalse($this->user->hasCredential('post.edit'));
        $this->assertFalse($this->user->hasCredential('post.review'));
        $this->assertFalse($this->user->hasCredential('team.view'));
        $this->assertFalse($this->user->hasCredential('login'));
    }

    public function testRevokingAnUnheldRoleIsANoOp(): void
    {
        $this->user->grantRole('editor');
        $this->user->markClean();

        $this->user->revokeRole('reviewer');

        $this->assertTrue($this->user->hasRole('editor'));
        $this->assertTrue($this->user->hasCredential('post.edit'));
        $this->assertFalse($this->user->isDirty(), 'a no-op must not dirty the user');
    }

    public function testRoleApiIsUsableBeforeInitialize(): void
    {
        $fresh = new RevocationRbacUser();

        // Public API reachable before initialize() populated $roles; these used to
        // raise "in_array(): Argument #2 must be of type array, null given".
        $this->assertFalse($fresh->hasRole('editor'));
        $this->assertSame([], $fresh->getRoles());
        $fresh->revokeAllRoles();
        $fresh->revokeRole('editor');
        $this->assertSame([], $fresh->getRoles());
    }
}
