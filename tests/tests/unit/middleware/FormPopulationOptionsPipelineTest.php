<?php

use Quiote\Testing\HttpTestCase;

/**
 * Population options a view publishes with FormPopulationConfig::merge() + RequestState::publish()
 * reach both population paths: FormPopulationMiddleware on a GET, and ValidationMiddleware's
 * repopulation after a failed submission. An edit page sets include_hidden_inputs=false so a failed
 * submission cannot overwrite the ids and row keys its hidden inputs carry.
 */
final class FormPopulationOptionsPipelineTest extends HttpTestCase
{
    protected ?string $contextName = 'form-population-test';

    public function testWithoutOptionsTheHiddenInputAndTheOtherFieldAreFilled(): void
    {
        $this->get('/form-population/options-control')
            ->assertOk()
            ->assertSee('name="Token" value="stored-token"')
            ->assertSee('name="Secret" value="stored-secret"');
    }

    public function testOnAGetTheViewsOptionsKeepTheHiddenInputAndTheSkippedFieldUnfilled(): void
    {
        $this->get('/form-population/options')
            ->assertOk()
            ->assertSee('<input type="text" name="Name" value="Stored name">')
            ->assertSee('name="Token" value="rendered-token"')
            ->assertDontSee('stored-token')
            ->assertSee('<input type="text" name="Secret">')
            ->assertDontSee('stored-secret');
    }

    public function testAfterAFailedSubmissionTheErrorViewsOptionsKeepTheHiddenInputAndTheSkippedFieldUnfilled(): void
    {
        $this->post('/form-population/options', ['Name' => 'ab', 'Token' => 'posted-token', 'Secret' => 'posted-secret'])
            ->assertStatus(400)
            ->assertSee('value="ab"')
            ->assertSee('name="Token" value="rendered-token"')
            ->assertDontSee('posted-token')
            ->assertSee('<input type="text" name="Secret">')
            ->assertDontSee('posted-secret');
    }
}
