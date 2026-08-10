<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use Quiote\Testing\UnitTestCase;
use Quiote\Validator\DependencyManager;

/**
 * `populateArgumentBaseKeyRefs()` turns a repeated-field base path into an
 * sprintf template that {@see DependencyManager::checkDependencies()} fills
 * from the current path parts.
 *
 * Only the *empty* brackets become placeholders: those are the positions that
 * vary per row of a repeated field. A bracket that already names a key is a
 * fixed part of the path and must survive untouched, or a dependency would
 * resolve against the wrong element.
 *
 * The position numbers are what tie a placeholder to its part, so they count
 * every bracket rather than only the empty ones -- which is why the first
 * placeholder in `foo[][bar][]` is `%2$s` and not `%1$s`.
 */
final class DependencyManagerKeyRefsTest extends UnitTestCase
{
    #[DataProvider('baseStrings')]
    public function testEmptyBracketsBecomePositionalPlaceholders(string $input, string $expected): void
    {
        $this->assertSame($expected, DependencyManager::populateArgumentBaseKeyRefs($input));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function baseStrings(): array
    {
        return [
            'the documented example' => ['foo[][bar][]', 'foo[%2$s][bar][%4$s]'],
            'no brackets at all' => ['foo', 'foo'],
            'empty string' => ['', ''],
            'one empty bracket' => ['foo[]', 'foo[%2$s]'],
            'only named brackets' => ['foo[bar][baz]', 'foo[bar][baz]'],
            'leading empty bracket' => ['[]', '[%2$s]'],
            'consecutive empty brackets' => ['foo[][]', 'foo[%2$s][%3$s]'],
            'named then empty' => ['foo[bar][]', 'foo[bar][%3$s]'],
            'numeric key is a named key' => ['foo[0][]', 'foo[0][%3$s]'],
        ];
    }

    /**
     * A named bracket still advances the position counter, so the
     * placeholders line up with the path parts rather than with the count of
     * empty brackets before them.
     */
    public function testNamedBracketsStillAdvanceThePositionCounter(): void
    {
        $this->assertSame(
            'a[fixed][%3$s][alsofixed][%5$s]',
            DependencyManager::populateArgumentBaseKeyRefs('a[fixed][][alsofixed][]'),
        );
    }

    /** The template is what vsprintf() consumes, so it has to survive that. */
    public function testTheResultIsAUsableSprintfTemplate(): void
    {
        $template = DependencyManager::populateArgumentBaseKeyRefs('foo[][bar][]');

        $this->assertSame('foo[1][bar][3]', vsprintf($template, ['foo', '1', 'bar', '3']));
    }
}
