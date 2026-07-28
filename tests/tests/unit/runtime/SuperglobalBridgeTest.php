<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;
use Quiote\Runtime\Superglobals\SuperglobalBridge;

final class SuperglobalBridgeTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServer = [];
    /** @var array<string, mixed> */
    private array $savedGet = [];
    /** @var array<string, mixed> */
    private array $savedPost = [];
    /** @var array<string, mixed> */
    private array $savedCookie = [];
    /** @var array<string, mixed> */
    private array $savedRequest = [];
    /** @var array<string, mixed> */
    private array $savedFiles = [];

    #[Before]
    public function captureSuperglobals(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedGet = $_GET;
        $this->savedPost = $_POST;
        $this->savedCookie = $_COOKIE;
        $this->savedRequest = $_REQUEST;
        $this->savedFiles = $_FILES;
    }

    #[After]
    public function restoreSuperglobals(): void
    {
        $_SERVER = $this->savedServer;
        $_GET = $this->savedGet;
        $_POST = $this->savedPost;
        $_COOKIE = $this->savedCookie;
        $_REQUEST = $this->savedRequest;
        $_FILES = $this->savedFiles;
    }

    public function testHydratePopulatesEveryRequestSuperglobal(): void
    {
        $bridge = new SuperglobalBridge();
        $request = (new Psr17Factory())
            ->createServerRequest('POST', 'https://public.example/thing?q=hello', [
                'REQUEST_METHOD' => 'POST',
                'SCRIPT_NAME' => '/index.php',
                'REQUEST_TIME_FLOAT' => 1234.5,
            ])
            ->withQueryParams(['q' => 'hello'])
            ->withParsedBody(['field' => 'value'])
            ->withCookieParams(['sid' => 'abc']);

        $bridge->hydrate($request);

        $this->assertSame('POST', $_SERVER['REQUEST_METHOD']);
        $this->assertSame('/index.php', $_SERVER['SCRIPT_NAME']);
        $this->assertSame(1234.5, $_SERVER['REQUEST_TIME_FLOAT']);
        $this->assertSame(['q' => 'hello'], $_GET);
        $this->assertSame(['field' => 'value'], $_POST);
        $this->assertSame(['sid' => 'abc'], $_COOKIE);
    }

    public function testHydrateKeepsTheProcessOwnServerEntries(): void
    {
        $_SERVER['QUIOTE_BRIDGE_MARKER'] = 'process-level';
        $bridge = new SuperglobalBridge();

        $bridge->hydrate((new Psr17Factory())->createServerRequest('GET', '/', ['REQUEST_METHOD' => 'GET']));

        // argv/PATH/PWD and friends have to survive, or CLI-hosted runtimes lose
        // their own environment the moment they serve a request.
        $this->assertSame('process-level', $_SERVER['QUIOTE_BRIDGE_MARKER']);
        $this->assertSame('GET', $_SERVER['REQUEST_METHOD']);
    }

    public function testRequestIsTheMergeOfGetAndPostWithPostWinning(): void
    {
        $bridge = new SuperglobalBridge();
        $request = (new Psr17Factory())->createServerRequest('POST', '/')
            ->withQueryParams(['shared' => 'from-get', 'only-get' => '1'])
            ->withParsedBody(['shared' => 'from-post', 'only-post' => '2']);

        $bridge->hydrate($request);

        $this->assertSame('from-post', $_REQUEST['shared']);
        $this->assertSame('1', $_REQUEST['only-get']);
        $this->assertSame('2', $_REQUEST['only-post']);
    }

    public function testANonArrayParsedBodyLeavesPostEmpty(): void
    {
        $bridge = new SuperglobalBridge();
        $psr17 = new Psr17Factory();
        $request = $psr17->createServerRequest('POST', '/')
            ->withBody($psr17->createStream('raw json'));

        $bridge->hydrate($request);

        $this->assertSame([], $_POST);
    }

    public function testUploadedFilesBecomeAFilesShapedArrayWithoutATempName(): void
    {
        $psr17 = new Psr17Factory();
        $bridge = new SuperglobalBridge();
        $file = $psr17->createUploadedFile(
            $psr17->createStream('contents'),
            8,
            UPLOAD_ERR_OK,
            'report.pdf',
            'application/pdf',
        );

        $bridge->hydrate($psr17->createServerRequest('POST', '/')->withUploadedFiles(['doc' => $file]));

        $entry = $_FILES['doc'];
        $this->assertIsArray($entry);
        $this->assertSame('report.pdf', $entry['name']);
        $this->assertSame('application/pdf', $entry['type']);
        $this->assertSame(8, $entry['size']);
        $this->assertSame(UPLOAD_ERR_OK, $entry['error']);
        // A PSR-7 upload may be backed by a stream with no file behind it, so
        // there is nothing honest to put here -- see the class docblock.
        $this->assertSame('', $entry['tmp_name']);
    }

    public function testNestedUploadGroupsAreCarriedThroughRatherThanDropped(): void
    {
        $psr17 = new Psr17Factory();
        $bridge = new SuperglobalBridge();
        $file = $psr17->createUploadedFile($psr17->createStream('x'), 1, UPLOAD_ERR_OK, 'a.txt');

        $bridge->hydrate($psr17->createServerRequest('POST', '/')->withUploadedFiles(['docs' => ['0' => $file]]));

        $group = $_FILES['docs'];
        $this->assertIsArray($group);
        $first = $group['0'];
        $this->assertIsArray($first);
        $this->assertSame('a.txt', $first['name']);
    }

    public function testDehydrateRestoresTheBaselineAndClearsTheRest(): void
    {
        $_SERVER = ['QUIOTE_BRIDGE_MARKER' => 'baseline'];
        $bridge = new SuperglobalBridge();

        $bridge->hydrate(
            (new Psr17Factory())->createServerRequest('GET', '/', ['HTTP_COOKIE' => 'sid=abc'])
                ->withQueryParams(['a' => '1'])
                ->withCookieParams(['sid' => 'abc'])
        );
        $this->assertArrayHasKey('HTTP_COOKIE', $_SERVER);

        $bridge->dehydrate();

        // Nothing from the finished request may still be visible to the next one.
        $this->assertSame(['QUIOTE_BRIDGE_MARKER' => 'baseline'], $_SERVER);
        $this->assertSame([], $_GET);
        $this->assertSame([], $_POST);
        $this->assertSame([], $_COOKIE);
        $this->assertSame([], $_REQUEST);
        $this->assertSame([], $_FILES);
    }
}
