<?php

use Quiote\Testing\HttpTestCase;

/**
 * Form population through the whole pipeline: a view names the values for its edit form and the
 * rendered page carries them, a failed submission shows what the user typed, and a page that asks
 * for nothing is left as rendered.
 */
final class FormPopulationPipelineTest extends HttpTestCase
{
    protected ?string $contextName = 'form-population-test';

    public function testAViewPrefillsItsEditFormOnAGet(): void
    {
        $this->get('/form-population/edit')
            ->assertOk()
            ->assertSee('<input type="text" name="Name" value="Stored name">')
            ->assertSee('<option value="b" selected>B</option>')
            ->assertSee('<input type="checkbox" name="Active" value="1" checked>')
            ->assertSee('<textarea name="Notes">Stored notes</textarea>');
    }

    public function testAFormTheViewDoesNotNameKeepsItsMarkup(): void
    {
        $this->get('/form-population/edit?q=from-the-query')
            ->assertOk()
            ->assertSee('<input type="text" name="q">')
            ->assertDontSee('from-the-query');
    }

    public function testAFailedSubmissionShowsWhatTheUserTypedRatherThanTheStoredRecord(): void
    {
        $this->post('/form-population/edit', ['Name' => 'ab'])
            ->assertStatus(400)
            ->assertSee('value="ab"')
            ->assertDontSee('Stored name');
    }

    public function testAPageThatAsksForNoPopulationIsLeftAsRendered(): void
    {
        $this->get('/form-population/plain?Name=from-the-query')
            ->assertOk()
            ->assertSee('<input type="text" name="Name">')
            ->assertDontSee('from-the-query');
    }
}
