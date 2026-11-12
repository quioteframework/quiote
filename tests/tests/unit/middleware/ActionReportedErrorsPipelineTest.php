<?php

use Quiote\Testing\HttpTestCase;

/**
 * An action that passes validation and then rejects the request on a business rule of its own
 * reports the error on its validation manager and returns its Error view. That view has to read the
 * same manager: the one validation ran with, which the action wrote to.
 */
final class ActionReportedErrorsPipelineTest extends HttpTestCase
{
    protected ?string $contextName = 'form-population-test';

    public function testTheErrorViewSeesTheErrorTheActionReported(): void
    {
        $this->post('/action-errors/report', ['Name' => 'Ada'])
            ->assertOk()
            ->assertSee('<p>errors: Buyer contact is missing.</p>');
    }

    public function testTheActionAndItsViewShareTheManagerValidationRanWith(): void
    {
        $this->post('/action-errors/report', ['Name' => 'Ada'])
            ->assertSee('<p>validated: Name</p>');
    }
}
