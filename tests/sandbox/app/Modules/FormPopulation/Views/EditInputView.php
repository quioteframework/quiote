<?php
declare(strict_types=1);

namespace Sandbox\Modules\FormPopulation\Views;

use Quiote\Request\WebRequest;
use Quiote\View\View;

/**
 * The edit form, rendered with no values in its markup: populateForm() supplies the stored
 * record, and FormPopulationMiddleware writes it into the fields. A second form on the page is
 * not named, so it keeps its markup.
 */
class EditInputView extends View
{
    public const string FORM = '<form id="record" action="/form-population/edit" method="post">'
        . '<input type="text" name="Name">'
        . '<select name="Kind"><option value="a">A</option><option value="b">B</option></select>'
        . '<input type="checkbox" name="Active" value="1">'
        . '<textarea name="Notes"></textarea>'
        . '</form>'
        . '<form id="search" action="/form-population/search" method="get">'
        . '<input type="text" name="q">'
        . '</form>';

    public function execute(WebRequest $rd): string
    {
        return $this->executeHtml($rd);
    }

    public function executeHtml(WebRequest $rd): string
    {
        $this->populateForm('record', [
            'Name' => 'Stored name',
            'Kind' => 'b',
            'Active' => '1',
            'Notes' => 'Stored notes',
        ]);

        return '<!DOCTYPE html><html><body>' . self::FORM . '</body></html>';
    }
}
