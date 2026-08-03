<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quiote\Http\HttpStatus;

class HttpStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: int|string}>
     */
    public static function validCodeProvider(): array
    {
        return [
            'lower bound' => [100],
            'upper bound' => [599],
            'ok' => [200],
            'created' => [201],
            'permanent redirect' => [308],
            'unprocessable content' => [422],
            'too many requests' => [429],
            'unavailable for legal reasons' => [451],
            'insufficient storage' => [507],
            'network authentication required' => [511],
            'unassigned but in range' => [499],
            'numeric string' => ['422'],
        ];
    }

    /**
     * @return array<string, array{0: int|string}>
     */
    public static function invalidCodeProvider(): array
    {
        return [
            'below lower bound' => [99],
            'above upper bound' => [600],
            'zero' => [0],
            'negative' => [-200],
            'empty string' => [''],
            'non numeric string' => ['abc'],
            'mixed string' => ['200 OK'],
            'float-ish string' => ['200.5'],
            'signed string' => ['+200'],
            'whitespace' => [' 200'],
        ];
    }

    #[DataProvider('validCodeProvider')]
    public function testIsValidAcceptsAnythingInRange(int|string $code): void
    {
        $this->assertTrue(HttpStatus::isValid($code));
    }

    #[DataProvider('invalidCodeProvider')]
    public function testIsValidRejectsOutOfRangeAndNonNumeric(int|string $code): void
    {
        $this->assertFalse(HttpStatus::isValid($code));
    }

    public function testPhraseReturnsRegisteredPhrase(): void
    {
        $this->assertSame('OK', HttpStatus::phrase(200));
        $this->assertSame('Unprocessable Content', HttpStatus::phrase(422));
        $this->assertSame('Too Many Requests', HttpStatus::phrase('429'));
        $this->assertSame('Permanent Redirect', HttpStatus::phrase(308));
    }

    public function testPhraseFallsBackToClassDerivedTextForUnlistedValidCode(): void
    {
        $this->assertSame('Informational', HttpStatus::phrase(199));
        $this->assertSame('Success', HttpStatus::phrase(299));
        $this->assertSame('Redirection', HttpStatus::phrase(399));
        $this->assertSame('Client Error', HttpStatus::phrase(499));
        $this->assertSame('Server Error', HttpStatus::phrase(599));
    }

    public function testPhraseIsEmptyForInvalidCode(): void
    {
        $this->assertSame('', HttpStatus::phrase(600));
        $this->assertSame('', HttpStatus::phrase('nope'));
    }

    public function testIsRedirectCoversLocationBearingCodesOnly(): void
    {
        foreach ([301, 302, 303, 307, 308] as $code) {
            $this->assertTrue(HttpStatus::isRedirect($code), "expected $code to be a redirect");
        }

        // 304 is a 3xx but carries no Location.
        $this->assertFalse(HttpStatus::isRedirect(304));
        $this->assertFalse(HttpStatus::isRedirect(200));
        $this->assertFalse(HttpStatus::isRedirect(404));
        $this->assertFalse(HttpStatus::isRedirect(600));
    }
}
