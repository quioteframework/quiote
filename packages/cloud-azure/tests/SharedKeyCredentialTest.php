<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Quiote\Storage\Azure\AzureStorageException;
use Quiote\Storage\Azure\SharedKeyCredential;

final class SharedKeyCredentialTest extends TestCase
{
    public function testAuthorizationHeaderIsStableForTheSameRequest(): void
    {
        $credential = new SharedKeyCredential(base64_encode('key-material'));

        $first = $credential->authorizationHeader('myaccount', 'GET', '/sessions/abc.json', [], ['x-ms-date' => 'Wed, 21 Oct 2015 07:28:00 GMT']);
        $second = $credential->authorizationHeader('myaccount', 'GET', '/sessions/abc.json', [], ['x-ms-date' => 'Wed, 21 Oct 2015 07:28:00 GMT']);

        $this->assertStringStartsWith('SharedKey myaccount:', $first);
        $this->assertSame($first, $second);
    }

    public function testAuthorizationHeaderChangesWithTheRequestItSigns(): void
    {
        $credential = new SharedKeyCredential(base64_encode('key-material'));
        $headers = ['x-ms-date' => 'Wed, 21 Oct 2015 07:28:00 GMT'];

        $get = $credential->authorizationHeader('myaccount', 'GET', '/sessions/abc.json', [], $headers);
        $put = $credential->authorizationHeader('myaccount', 'PUT', '/sessions/abc.json', [], $headers);

        $this->assertNotSame($get, $put);
    }

    public function testInvalidBase64AccountKeyThrows(): void
    {
        $credential = new SharedKeyCredential('not valid base64!!');

        $this->expectException(AzureStorageException::class);
        $credential->authorizationHeader('myaccount', 'GET', '/sessions/abc.json', [], []);
    }
}
