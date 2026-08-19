<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Replay\Recording\RedactionMode;
use Quiote\Replay\Recording\Redactor;

final class RedactorTest extends TestCase
{
    private function redactor(RedactionMode $mode = RedactionMode::Drop): Redactor
    {
        return new Redactor(
            ['authorization', 'cookie', 'set-cookie'],
            ['password', 'secret', 'token'],
            ['_csrf', 'auth.token'],
            $mode,
        );
    }

    public function testDropModeReplacesAHeaderValueVerbatim(): void
    {
        $headers = $this->redactor()->redactHeaders(['Authorization' => ['Bearer secret-token-value']]);

        $this->assertSame(['[REDACTED]'], $headers['Authorization']);
    }

    public function testHeaderMatchingIsCaseInsensitive(): void
    {
        $headers = $this->redactor()->redactHeaders(['AUTHORIZATION' => ['Bearer x']]);

        $this->assertSame(['[REDACTED]'], $headers['AUTHORIZATION']);
    }

    public function testANonDeniedHeaderPassesThroughUnchanged(): void
    {
        $headers = $this->redactor()->redactHeaders(['X-Request-Id' => ['abc-123']]);

        $this->assertSame(['abc-123'], $headers['X-Request-Id']);
    }

    public function testNestedParamsAreRedactedRegardlessOfDepth(): void
    {
        $params = $this->redactor()->redactParams([
            'user' => ['name' => 'Ada', 'credentials' => ['password' => 'letmein']],
        ]);

        $user = $params['user'];
        $this->assertIsArray($user);
        $this->assertSame('Ada', $user['name']);
        $credentials = $user['credentials'];
        $this->assertIsArray($credentials);
        $this->assertSame('[REDACTED]', $credentials['password']);
    }

    public function testABodyFieldNameMatchingADeniedParamIsRedacted(): void
    {
        $params = $this->redactor()->redactParams(['secret' => 'top-secret-value']);

        $this->assertSame('[REDACTED]', $params['secret']);
    }

    public function testCookiesAreRedactedAgainstTheParamDenylist(): void
    {
        $cookies = $this->redactor()->redactCookies(['token' => 'abc', 'theme' => 'dark']);

        $this->assertSame('[REDACTED]', $cookies['token']);
        $this->assertSame('dark', $cookies['theme']);
    }

    public function testSessionKeysAreRedactedAgainstTheSessionDenylist(): void
    {
        $session = $this->redactor()->redactSession(['_csrf' => 'xyz', 'id' => 'sess-1']);

        $this->assertSame('[REDACTED]', $session['_csrf']);
        $this->assertSame('sess-1', $session['id']);
    }

    public function testHashModeProducesADeterministicNonReversibleDigest(): void
    {
        $redactor = $this->redactor(RedactionMode::Hash);
        $params = $redactor->redactParams(['secret' => 'top-secret-value']);
        $secret = $params['secret'];

        $this->assertIsString($secret);
        $this->assertStringStartsWith('sha256:', $secret);
        $this->assertSame('sha256:' . hash('sha256', 'top-secret-value'), $secret);
        $this->assertStringNotContainsString('top-secret-value', $secret);
    }

    public function testMaskModeKeepsOnlyTheLastFourCharacters(): void
    {
        $redactor = $this->redactor(RedactionMode::Mask);
        $params = $redactor->redactParams(['secret' => '1234567890']);

        $this->assertSame('******7890', $params['secret']);
    }

    public function testMaskModeOnAShortValueMasksItEntirely(): void
    {
        $redactor = $this->redactor(RedactionMode::Mask);
        $params = $redactor->redactParams(['secret' => 'ab']);

        $this->assertSame('**', $params['secret']);
    }

    /**
     * The strongest guarantee this class makes: a denied value never appears anywhere in the
     * fully encoded output, regardless of which section carried it.
     */
    public function testADeniedValueNeverAppearsAnywhereInTheEncodedOutput(): void
    {
        $redactor = $this->redactor();
        $secretValue = 'super-sensitive-value-9f3a';

        $headers = $redactor->redactHeaders(['Authorization' => ["Bearer $secretValue"]]);
        $cookies = $redactor->redactCookies(['token' => $secretValue]);
        $session = $redactor->redactSession(['auth.token' => $secretValue]);
        $params = $redactor->redactParams(['nested' => ['password' => $secretValue]]);

        $encoded = json_encode(compact('headers', 'cookies', 'session', 'params'));
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secretValue, $encoded);
    }
}
